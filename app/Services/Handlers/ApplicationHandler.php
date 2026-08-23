<?php

namespace App\Services\Handlers;

use App\Models\InstallmentRequest;
use App\Models\Machine;
use App\Models\WhatsappConversation;
use App\Services\AiMemoryResolver;
use App\Services\DocumentDataExtractor;
use App\Services\Ocr\OcrProviderInterface;
use Illuminate\Support\Facades\Storage;

class ApplicationHandler
{
    public function handle(WhatsappConversation $conversation, string $message): array
    {
        $conversation->refresh();

        $payload = $this->payload($conversation);
        $machine = $this->currentMachine($conversation);

        /*
         * "احنا بنقدم على أنهي مكنة؟" / "رفعنا ايه لحد دلوقتي؟" - العميل
         * بيسأل عن حالة الطلب نفسه، مش بيبعت بيانات جديدة. من غيرها كان
         * بيترد عليه بنفس رسالة "ابعتلي صورة البطاقة" الثابتة تاني، من
         * غير ما يجاوب على سؤاله خالص.
         */
        if ($machine && $this->isApplicationStatusQuestion($message)) {
            return $this->reply($conversation, $this->applicationStatusSummary($conversation, $machine, $payload));
        }

        if (($conversation->pending_question ?? null) === 'application_documents') {
            return $this->reply($conversation, $this->documentPrompt($payload));
        }

        $blocked = $this->blockedByExistingRequest($conversation);

        if ($blocked) {
            return $this->reply($conversation, $blocked);
        }

        $application = $payload['application'] ?? [];

        if (!$machine) {
            return $this->reply($conversation, 'تمام يا فندم، تحب تقدم على أنهي مكنة؟');
        }

        $application['machine_id'] = $machine->id;
        $application['machine_name'] = $this->machineDisplayName($machine);

        if (empty($application['payment_method'])) {
            $application['payment_method'] = $this->detectPaymentMethod($message);
        }

        /*
         * لو العميل أصلاً كان بيحسب قسط قبل كده في نفس المحادثة (last_months
         * محفوظة من installment_calc)، معنى كده إنه قال قسط بالفعل.
         * منسألوش تاني \"كاش ولا تقسيط؟\" ومنستخدمش عدد الشهور اللي حسبه.
         */
        if (empty($application['payment_method']) && !empty($payload['last_months'])) {
            $application['payment_method'] = 'installment';

            if (empty($application['installment_months'])) {
                $application['installment_months'] = $payload['last_months'];
            }
        }

        if (empty($application['payment_method'])) {
            $this->saveState($conversation, $application, ['payment_method'], null);

            return $this->reply($conversation, 'تمام يا فندم، حضرتك عاوز تدفع كاش ولا تقسيط؟');
        }

        if ($application['payment_method'] === 'cash') {
            $this->saveState($conversation, $application, [], null);

            return $this->reply($conversation, 'تمام يا فندم، تعالى المعرض وهناخد إجراءات الشراء كاش على طول.');
        }

        $stateService = app(\App\Services\ApplicationStateService::class);

        if (!empty($payload['pending_conflicts'])) {
            $resolved = $stateService->resolveConflicts($payload['pending_conflicts'], $message);
            $application = array_merge($application, $resolved);

            $stillPending = array_diff_key($payload['pending_conflicts'], $resolved);

            if (!empty($stillPending)) {
                $payload['pending_conflicts'] = $stillPending;
                $payload['application'] = $application;

                $conversation->forceFill(['context_payload' => $payload])->save();

                return $this->reply($conversation, $stateService->conflictQuestion($stillPending));
            }

            $payload['pending_conflicts'] = null;

            /*
             * الرسالة دي كانت إجابة على سؤال التعارض بس ("التاني")، مش
             * بيانات طلب جديدة. لو سبناها تروح لاستخراج الـ AI العادي
             * تحت، كان بيحاول يستخرج منها/من سياق المحادثة تاني ويرجّع
             * نفس الرقم القديم كـ"مستخرج جديد" فيفتح نفس تعارض الهاتف
             * تاني من غير نهاية - ده كان بالظبط السبب إنه بيرجع يسأل نفس
             * السؤال تاني وتاني. بنقفل الدورة هنا على طول بدل ما نمر على
             * الاستخراج تاني.
             */
            return $this->finalizeApplicationTurn($conversation, $payload, $application, $message, $stateService);
        }

        $analysis = app(\App\Services\AiIntentClassifier::class)->classify($conversation, $message, [
            'mode' => 'application_data_extraction',
            'required_fields' => [
                'full_name',
                'national_id',
                'phone',
                'job_type',
                'income_proof',
                'work_address',
                'home_address',
                'installment_months',
            ],
            'current_application' => $application,
            'selected_machine' => [
                'id' => $machine->id,
                'name' => $this->machineDisplayName($machine),
            ],
        ]);

        $extracted = $analysis['application_data'] ?? [];
        $extracted = $stateService->reconcileAddressAssignment($application, $extracted);
        $conflicts = $stateService->detectConflicts($application, $extracted);

        if (!empty($conflicts)) {
            // Don't silently pick a value - merge only the non-conflicting
            // fields extracted this turn, hold the conflicting ones, and
            // ask which one is correct before touching them.
            $extracted = array_diff_key($extracted, $conflicts);
            $payload['pending_conflicts'] = $conflicts;
        }

        $application = $this->mergeApplicationData($application, $extracted);

        if (!empty($conflicts)) {
            $payload['application'] = $application;

            $conversation->forceFill([
                'last_topic' => 'application',
                'context_payload' => $payload,
            ])->save();

            return $this->reply($conversation, $stateService->conflictQuestion($conflicts));
        }

        return $this->finalizeApplicationTurn($conversation, $payload, $application, $message, $stateService);
    }

    /**
     * Shared tail for both the normal extraction path and the
     * conflict-resolution shortcut: apply the income-proof denial check,
     * refresh address components, compute what's still missing, and
     * either ask for it (progress-aware) or move on to document
     * collection once everything required is known.
     */
    private function finalizeApplicationTurn(
        WhatsappConversation $conversation,
        array $payload,
        array $application,
        string $message,
        \App\Services\ApplicationStateService $stateService
    ): array {
        if (empty($application['income_proof'])) {
            if ($this->messageDeniesIncomeProof($message) || $this->categorizeIncome((string) ($application['job_type'] ?? ''), '') === 'freelance') {
                $application['income_proof'] = 'لا يوجد';
            }
        }

        $application = $stateService->refreshAddressComponents($application);

        $isFreelance = $this->categorizeIncome(
            (string) ($application['job_type'] ?? ''),
            (string) ($application['income_proof'] ?? '')
        ) === 'freelance';

        $previousMissing = $payload['missing_fields'] ?? [];
        $missing = $stateService->missingFields($application, $isFreelance);

        if (!empty($missing)) {
            /*
             * اللي كان ناقص قبل وبقى موجود دلوقتي - من غيرها كل رد كان
             * بيرجع نفس القالب الثابت "ناقصني البيانات دي" تاني من غير
             * ما يقول إنه فعلاً استلم اللي العميل بعته، وده كان بيحس إن
             * البوت مش قاري كلامه أصلاً.
             */
            $newlyFilled = array_values(array_diff($previousMissing, $missing));

            /*
             * لو محصلش تقدم تاني ورا بعض (مفيش newlyFilled) وده مش أول
             * مرة بنسأل فيها أصلاً، بنعد المرات عشان الجملة الافتتاحية
             * تتغير بدل ما تفضل ثابتة حرفيًا - مش بنغير قايمة البيانات
             * الناقصة نفسها أبدًا. أول سؤال في الطلب لسه بيستخدم الصيغة
             * العادية لأنه مفيش "تكرار" حقيقي لسه.
             */
            $hasAskedBefore = array_key_exists('missing_fields', $payload);

            $noProgressStreak = ($hasAskedBefore && empty($newlyFilled))
                ? (int) ($payload['no_progress_streak'] ?? 0) + 1
                : 0;

            $this->saveState(
                $conversation,
                $application,
                $missing,
                null,
                ['no_progress_streak' => $noProgressStreak],
                $payload
            );

            return $this->reply(
                $conversation,
                $stateService->questionForMissing($missing, $application, $newlyFilled, $noProgressStreak)
            );
        }

        $requiredDocuments = $this->requiredDocuments($application);

        $payload['application'] = $application;
        $payload['missing_fields'] = [];
        $payload['documents_required'] = $requiredDocuments;
        $payload['documents_index'] = 0;
        $payload['documents_collected'] = [];

        $conversation->forceFill([
            'last_topic' => 'application',
            'pending_question' => 'application_documents',
            'context_payload' => $payload,
        ])->save();

        return $this->reply(
            $conversation,
            "تمام يا فندم، البيانات الأساسية مكتملة على {$application['machine_name']}.\n"
                . $this->documentPrompt($payload)
        );
    }

    public function handleDocument(WhatsappConversation $conversation, array $mediaItems): array
    {
        $conversation->refresh();

        $payload = $this->payload($conversation);
        $required = $payload['documents_required'] ?? [];
        $index = (int) ($payload['documents_index'] ?? 0);

        if (empty($required) || $index >= count($required)) {
            return $this->reply($conversation, 'تمام يا فندم، طلبك مكتمل بالفعل ومحتاجش مستندات تانية دلوقتي.');
        }

        $item = collect($mediaItems)->first(fn ($i) => is_array($i) && trim((string) ($i['path'] ?? '')) !== '');

        if (! $item) {
            return $this->reply($conversation, 'مقدرتش أستلم الصورة، ممكن تبعتها تاني؟');
        }

        $path = trim((string) $item['path']);
        $mime = strtolower(trim((string) ($item['mime'] ?? '')));
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            return $this->reply($conversation, 'مقدرتش ألاقي الصورة، ممكن تبعتها تاني؟');
        }

        $docType = $required[$index];

        $this->sendInstantAck($conversation, 'لحظات معايا يافندم، براجع البيانات.');

        $ocr = app(OcrProviderInterface::class)->recognize($disk->path($path), $mime);

        if (! ($ocr['ok'] ?? false)) {
            return $this->reply($conversation, 'مقدرتش أقرا بيانات من الصورة، ممكن تبعتها تاني بجودة أوضح؟');
        }

        $extraction = app(DocumentDataExtractor::class)->extract(
            (string) ($ocr['text'] ?? ''),
            $docType,
            $this->rulesTextFor($docType),
            [
                'application' => $payload['application'] ?? [],
                'documents_collected_so_far' => $payload['documents_collected'] ?? [],
            ]
        );

        if (! ($extraction['ok'] ?? false) || ! ($extraction['valid'] ?? false)) {
            $message = $extraction['violation_message']
                ?: 'المستند ده فيه مشكلة، ممكن تبعت صورة تانية؟';

            return $this->reply($conversation, $message);
        }

        $collected = $payload['documents_collected'] ?? [];
        $collected[$docType] = [
            'path' => $path,
            'fields' => $extraction['fields'] ?? [],
        ];

        $payload['documents_collected'] = $collected;
        $payload['documents_index'] = $index + 1;

        if ($payload['documents_index'] >= count($required)) {
            $this->createInstallmentRequest($conversation, $payload);

            $conversation->forceFill([
                'pending_question' => null,
                'context_payload' => $payload,
            ])->save();

            return $this->reply(
                $conversation,
                "تم رفع طلبك بنجاح يا فندم! ✅\n"
                    . "استلمنا كل بياناتك ومستنداتك، وطلبك دلوقتي تحت المراجعة من فريقنا.\n"
                    . 'هنتواصل معاك في أقرب وقت بمجرد ما نخلص المراجعة. شكرًا لثقتك فينا 🙏'
            );
        }

        $conversation->forceFill([
            'pending_question' => 'application_documents',
            'context_payload' => $payload,
        ])->save();

        return $this->reply($conversation, $this->documentPrompt($payload));
    }

    private function createInstallmentRequest(WhatsappConversation $conversation, array $payload): void
    {
        $application = $payload['application'] ?? [];
        $collected = $payload['documents_collected'] ?? [];

        $workStatus = $this->mapWorkStatus($application);

        $attributes = [
            'machine_id' => $application['machine_id'] ?? null,
            'whatsapp_conversation_id' => $conversation->id,
            'applicant_name' => $application['full_name'] ?? null,
            'applicant_phone' => $application['phone'] ?? $conversation->phone,
            'applicant_national_id' => $application['national_id'] ?? null,
            'applicant_address' => $application['home_address'] ?? null,
            'work_status' => $workStatus,
            'months' => $application['installment_months'] ?? null,
            'installment_type' => ((string) ($payload['last_system'] ?? '20')) === '30'
                ? 'امان بدون مصاريف'
                : 'امان',
            'status' => 'new',
            'staff_id' => $conversation->whatsappBot?->staff_id,
            'notes' => 'طبيعة الشغل من كلام العميل: ' . ($application['job_type'] ?? '-'),
        ];

        /*
         * الفورم في الداشبورد بيفرق حسب work_status: employee/self_employed
         * بيستخدموا work_address، أما no_income_proof (دخل حر) فليها
         * حقول تانية تمامًا (free_work_name مطلوب + free_work_address)
         * وwork_address بتبقى مخفية أصلاً - لو حطيناها هي هتضيع.
         */
        if ($workStatus === 'no_income_proof') {
            $attributes['free_work_name'] = $application['job_type'] ?? null;
            $attributes['free_work_address'] = $application['work_address'] ?? null;
        } else {
            $attributes['work_address'] = $application['work_address'] ?? null;
        }

        $documentAttributes = [];

        foreach ($collected as $docType => $doc) {
            $documentAttributes = array_merge($documentAttributes, $this->mapDocumentToAttributes($docType, $doc));
        }

        /*
         * البيانات اللي العميل كتبها في المحادثة (الاسم، الرقم القومي...)
         * أوثق من اللي الـ OCR استخرجها من صورة البطاقة، فهي اللي بتكسب
         * لو الاتنين موجودين. الحقول اللي مالهاش غير مصدر المستند (صور،
         * تاريخ ميلاد) بتفضل زي ما هي.
         */
        $attributes = array_merge(
            array_filter($documentAttributes, fn ($value) => $value !== null && $value !== ''),
            array_filter($attributes, fn ($value) => $value !== null && $value !== '')
        );

        InstallmentRequest::query()->create($attributes);
    }

    private function mapDocumentToAttributes(string $docType, array $doc): array
    {
        $fields = $doc['fields'] ?? [];
        $path = $doc['path'] ?? null;

        return match ($docType) {
            'id_card_front' => [
                'applicant_id_image' => $path,
                'applicant_national_id' => $fields['national_id'] ?? null,
                'applicant_name' => $fields['name'] ?? $fields['full_name'] ?? null,
                'applicant_birthdate' => $fields['birthdate'] ?? null,
                'applicant_age_ok' => true,
            ],
            'id_card_back' => [
                'applicant_id_back_image' => $path,
            ],
            'salary_slip' => [
                'salary_slip_file' => $path,
                'salary_amount' => $fields['salary'] ?? $fields['salary_amount'] ?? null,
                'salary_issue_date' => $fields['issue_date'] ?? $fields['document_date'] ?? null,
            ],
            'pension_statement' => [
                'pension_statement_file' => $path,
                'pension_amount' => $fields['pension'] ?? $fields['pension_amount'] ?? null,
            ],
            'activity_photo' => [
                'place_video' => [$path],
            ],
            'bank_statement' => [
                'free_income_proof_images' => [$path],
            ],
            default => [],
        };
    }

    private function requiredDocuments(array $application): array
    {
        $category = $this->categorizeIncome(
            (string) ($application['job_type'] ?? ''),
            (string) ($application['income_proof'] ?? '')
        );

        $base = ['id_card_front', 'id_card_back'];

        $categorySpecific = match ($category) {
            'pension' => ['pension_statement'],
            'business' => ['activity_photo'],
            'army' => ['bank_statement'],
            'freelance' => [],
            default => ['salary_slip'],
        };

        return array_merge($base, $categorySpecific);
    }

    /**
     * عمود work_status في installment_requests عبارة عن ENUM محدود
     * (employee, pension, self_employed, no_income_proof) - مينفعش
     * نحط فيه نص حر زي "سباك" أو "شغال في مصنع" (بيرمي SQL error).
     * النص الحر بيتسجل في notes، والعمود ده بياخد التصنيف المطابق بس.
     */
    private function mapWorkStatus(array $application): string
    {
        $category = $this->categorizeIncome(
            (string) ($application['job_type'] ?? ''),
            (string) ($application['income_proof'] ?? '')
        );

        $hasNoIncomeProof = trim((string) ($application['income_proof'] ?? '')) === 'لا يوجد';

        return match ($category) {
            'pension' => 'pension',
            'business' => 'self_employed',
            'freelance' => $hasNoIncomeProof ? 'no_income_proof' : 'self_employed',
            'army' => 'employee',
            default => $hasNoIncomeProof ? 'no_income_proof' : 'employee',
        };
    }

    public function categorizeIncome(string $jobType, string $incomeProof): string
    {
        $text = mb_strtolower($jobType . ' ' . $incomeProof);

        if (str_contains($text, 'معاش') || str_contains($text, 'تقاعد')) {
            return 'pension';
        }

        if (str_contains($text, 'جيش') || str_contains($text, 'قوات مسلحة')) {
            return 'army';
        }

        if (
            str_contains($text, 'نشاط') || str_contains($text, 'تجار')
            || str_contains($text, 'محل') || str_contains($text, 'مشروع')
        ) {
            return 'business';
        }

        if (
            str_contains($text, 'حر') || str_contains($text, 'نجار')
            || str_contains($text, 'سباك') || str_contains($text, 'حداد')
            || str_contains($text, 'كهربائي') || str_contains($text, 'نقاش')
            || str_contains($text, 'سواق') || str_contains($text, 'سائق')
            || str_contains($text, 'دليفري') || str_contains($text, 'صنايعي')
            || str_contains($text, 'حرفي') || str_contains($text, 'مبلط')
            || str_contains($text, 'عمالة') || str_contains($text, 'أرزقي')
            || str_contains($text, 'ارزقي') || str_contains($text, 'ميكانيكي')
            || str_contains($text, 'سباكه') || str_contains($text, 'نجاره')
            || str_contains($text, 'حداده') || str_contains($text, 'نقاشه')
        ) {
            return 'freelance';
        }

        return 'employee';
    }

    private function rulesTextFor(string $docType): string
    {
        $resolver = app(AiMemoryResolver::class);

        $titles = match ($docType) {
            'id_card_front', 'id_card_back' => ['استخراج الاسم من البطاقه'],
            'salary_slip' => ['الموظفين'],
            'pension_statement' => ['أصحاب المعاشات'],
            'activity_photo' => ['أصحاب الأنشطة التجارية', 'مراجعه صور النشاط'],
            'bank_statement' => ['الموظفين'],
            default => [],
        };

        $chunks = collect($titles)
            ->map(fn ($title) => $resolver->memoryByExactTitle($title))
            ->filter()
            ->map(fn ($memory) => trim((string) $memory->content))
            ->filter()
            ->values();

        return $chunks->isNotEmpty() ? $chunks->implode("\n---\n") : 'مفيش قواعد محددة، تحقق فقط إن المستند واضح وبيانات صحيحة.';
    }

    private function documentLabel(string $docType): string
    {
        return match ($docType) {
            'id_card_front' => 'صورة البطاقة (وش)',
            'id_card_back' => 'صورة البطاقة (ضهر)',
            'salary_slip' => 'مفردات المرتب',
            'pension_statement' => 'بيان المعاش',
            'activity_photo' => 'صورة النشاط/المحل',
            'bank_statement' => 'كشف الحساب لآخر 6 شهور',
            default => 'المستند المطلوب',
        };
    }

    private function documentPrompt(array $payload): string
    {
        $required = $payload['documents_required'] ?? [];
        $index = (int) ($payload['documents_index'] ?? 0);

        if (empty($required) || $index >= count($required)) {
            return 'تمام يا فندم، استلمنا كل المستندات المطلوبة.';
        }

        return 'ابعتلي ' . $this->documentLabel($required[$index]) . ' من فضلك.';
    }


    /**
     * "احنا بنقدم على أنهي مكنة؟" / "رفعنا ايه لحد دلوقتي؟" - a status
     * question about the in-progress application itself, not new data.
     */
    private function isApplicationStatusQuestion(string $message): bool
    {
        $keywords = [
            'بنقدم علي', 'بنقدم على', 'هنقدم علي', 'هنقدم على',
            'بنشتري ايه', 'هنشتري ايه', 'شاريين ايه', 'بنقدم فين',
            'رفعنا ايه', 'بعتلك ايه', 'بعتنا ايه', 'وصلنا لفين',
            'احنا مقدمين', 'اي مكنه', 'انهي مكنه', 'أي مكنة', 'أنهي مكنة',
            'الطلب بتاعنا', 'طلبنا وصل لفين', 'ناقصنا ايه', 'فاضلنا ايه',
        ];

        foreach ($keywords as $keyword) {
            if (mb_stripos($message, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Answers "what are we applying for / what have we sent so far"
     * directly: confirms the machine + price, what's already known, and
     * either the current document prompt or the still-missing data
     * fields, depending on which stage the application is at.
     */
    private function applicationStatusSummary(WhatsappConversation $conversation, Machine $machine, array $payload): string
    {
        $application = $payload['application'] ?? [];

        $priceText = $machine->cash_price
            ? number_format((float) $machine->cash_price) . ' جنيه'
            : null;

        $lines = [
            "حضرتك مقدم على {$this->machineDisplayName($machine)}"
                . ($priceText ? " (سعرها كاش {$priceText})" : '') . '.',
        ];

        $labels = [
            'full_name' => 'الاسم',
            'national_id' => 'الرقم القومي',
            'phone' => 'رقم الموبايل',
            'job_type' => 'طبيعة الشغل',
            'income_proof' => 'إثبات الدخل',
            'work_address' => 'عنوان الشغل',
            'home_address' => 'عنوان السكن',
            'installment_months' => 'مدة التقسيط',
        ];

        $known = collect($labels)
            ->filter(fn ($label, $key) => filled($application[$key] ?? null))
            ->implode(' و');

        if ($known !== '') {
            $lines[] = "استلمنا منك: {$known}.";
        }

        if (($conversation->pending_question ?? null) === 'application_documents') {
            $lines[] = $this->documentPrompt($payload);

            return implode(' ', $lines);
        }

        $stateService = app(\App\Services\ApplicationStateService::class);

        $isFreelance = $this->categorizeIncome(
            (string) ($application['job_type'] ?? ''),
            (string) ($application['income_proof'] ?? '')
        ) === 'freelance';

        $missing = $stateService->missingFields($application, $isFreelance);

        $lines[] = empty($missing)
            ? 'البيانات الأساسية مكتملة، جاري استكمال المستندات.'
            : $stateService->questionForMissing($missing, $application);

        return implode("\n", $lines);
    }

    private function detectPaymentMethod(string $message): ?string
    {
        $text = mb_strtolower(trim($message));

        if ($text === '') {
            return null;
        }

        if (str_contains($text, 'كاش') || str_contains($text, 'نقدا') || str_contains($text, 'نقدي')) {
            return 'cash';
        }

        if (str_contains($text, 'قسط') || str_contains($text, 'تقسيط')) {
            return 'installment';
        }

        return null;
    }

    /**
     * شبكة أمان لو الاستخراج بالـ AI فشل يحدد income_proof رغم إن
     * العميل رد صراحة إنه مالوش إثبات دخل (بأي صيغة).
     */
    private function messageDeniesIncomeProof(string $message): bool
    {
        $text = trim($message);

        if ($text === '') {
            return false;
        }

        $phrases = [
            'لا يوجد', 'مش معايا', 'مش معاه', 'مفيش', 'مافيش', 'معيش',
            'ملكش', 'مالكش', 'شغال حر', 'دخل حر',
        ];

        foreach ($phrases as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }

        /*
         * "لا" لوحدها كلمة قصيرة وخطر إنها تتطابق جزء من كلمة تانية
         * (زي "لازم")، فبنتأكد إنها كلمة مستقلة في الرسالة.
         */
        $words = preg_split('/\s+/u', $text) ?: [];

        return in_array('لا', $words, true) || in_array('لأ', $words, true);
    }

    /**
     * ميسمحش بطلب جديد لو العميل عنده طلب سابق لسه شغال، إلا في حالتين:
     * الطلب السابق اتوافق عليه ومرّ عليه 6 شهور، أو الطلب السابق اترفض/اتلغى.
     * برجّع نص الرد لو المفروض نوقف، أو null لو مفيش مانع.
     */
    private function blockedByExistingRequest(WhatsappConversation $conversation): ?string
    {
        $existing = InstallmentRequest::query()
            ->where('whatsapp_conversation_id', $conversation->id)
            ->latest('id')
            ->first();

        if (! $existing) {
            return null;
        }

        if (in_array($existing->status, ['rejected', 'canceled'], true)) {
            return null;
        }

        if ($existing->status === 'approved') {
            $approvedAt = $existing->status_updated_at ?? $existing->updated_at;

            if (! $approvedAt) {
                return null;
            }

            $eligibleAt = $approvedAt->copy()->addMonths(6);

            if (now()->greaterThanOrEqualTo($eligibleAt)) {
                return null;
            }

            return "طلبك رقم #{$existing->id} كان اتوافق عليه، ونظامنا بيسمح بطلب جديد بعد 6 شهور من تاريخ الموافقة."
                . "\n" . 'تقدر تعمل طلب جديد ابتداءً من ' . $eligibleAt->format('Y-m-d') . '.';
        }

        return "طلبك رقم #{$existing->id} لسه تحت المراجعة، وهنتواصل معاك أول ما نخلص. مش محتاج تعمل طلب جديد دلوقتي.";
    }

    private function currentMachine(WhatsappConversation $conversation): ?Machine
    {
        /*
         * لو فيه طلب تقديم شغال بالفعل ومقفول على مكنة معينة، لازم يفضل
         * على نفس المكنة دي دايمًا - مش يتأثر بآخر مكنة اتعرضت بسبب سؤال
         * جانبي (زي "طيب وهي كام دايو 2؟" وسط طلب على هوجن ٤). last_machine_id
         * بيتحدث لأي مكنة بتتعرض حتى لو العميل مش بيقدم عليها فعلاً - كان
         * ده بيخلي الطلب "يقفز" لمكنة تانية بمجرد ما العميل يسأل عليها.
         */
        $lockedMachineId = $this->applicationLockedMachineId($conversation);

        if ($lockedMachineId) {
            $machine = Machine::query()->with('brand')->find($lockedMachineId);

            if ($machine) {
                return $machine;
            }
        }

        if (! empty($conversation->last_machine_id)) {
            $machine = Machine::query()
                ->with('brand')
                ->find((int) $conversation->last_machine_id);

            if ($machine) {
                return $machine;
            }
        }

        $ids = $conversation->last_machine_ids ?? [];

        if (is_string($ids)) {
            $ids = json_decode($ids, true) ?: [];
        }

        if (!is_array($ids) || empty($ids)) {
            return null;
        }

        if (count($ids) > 1) {
            return null;
        }

        return Machine::query()->with('brand')->find($ids[0]);
    }

    private function applicationLockedMachineId(WhatsappConversation $conversation): ?int
    {
        if (! in_array($conversation->pending_question ?? null, ['application_missing_data', 'application_documents'], true)) {
            return null;
        }

        $machineId = $this->payload($conversation)['application']['machine_id'] ?? null;

        return $machineId ? (int) $machineId : null;
    }

    private function mergeApplicationData(array $current, array $extracted): array
    {
        foreach ($extracted as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value)) {
                $value = trim($value);

                if ($value === '') {
                    continue;
                }
            }

            $current[$key] = $value;
        }

        return $current;
    }

    private function payload(WhatsappConversation $conversation): array
    {
        $payload = $conversation->context_payload ?? [];

        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?: [];
        }

        return is_array($payload) ? $payload : [];
    }

    private function saveState(
        WhatsappConversation $conversation,
        array $application,
        array $missing,
        ?string $pendingQuestion,
        array $extraPayload = [],
        ?array $basePayload = null
    ): void {
        /*
         * لو المتصل عنده نسخة payload محدّثة بالفعل (زي finalizeApplicationTurn
         * بعد ما يمسح pending_conflicts محليًا)، لازم نبني عليها هي، مش
         * نعيد قراءة $conversation->context_payload من جديد - ده كان
         * بيرجّع أي تعديل محلي (زي pending_conflicts = null) للحالة
         * القديمة تاني، فتعارض اترد عليه كان بيرجع يظهر تاني من غير نهاية.
         */
        $payload = $basePayload ?? $this->payload($conversation);

        $payload['application'] = $application;
        $payload['missing_fields'] = $missing;
        $payload = array_merge($payload, $extraPayload);

        $conversation->forceFill([
            'last_topic' => 'application',
            'pending_question' => $pendingQuestion ?? (empty($missing) ? null : 'application_missing_data'),
            'context_payload' => $payload,
        ])->save();
    }

    /**
     * رسالة فورية بديل الصمت وقت مراجعة المستند (OCR + AI ممكن ياخدوا
     * كذا ثانية). best-effort - أي فشل هنا مايوقفش معالجة المستند نفسها.
     */
    private function sendInstantAck(WhatsappConversation $conversation, string $text): void
    {
        try {
            $lastIncoming = $conversation->messages()
                ->where('direction', 'incoming')
                ->latest('id')
                ->first();

            $botId = data_get($lastIncoming?->payload, 'bot_id') ?: $conversation->whatsapp_bot_id;
            $jid = data_get($lastIncoming?->payload, 'reply_jid')
                ?: data_get($lastIncoming?->payload, 'from');

            if (! $botId || ! $jid) {
                return;
            }

            $url = config('services.whatsapp.worker_url') . '/send-message';

            \Illuminate\Support\Facades\Http::connectTimeout(5)
                ->timeout(15)
                ->withHeaders([
                    'X-BOT-TOKEN' => config('services.whatsapp.bot_token'),
                    'Accept' => 'application/json',
                ])
                ->post($url, [
                    'bot_id' => (string) $botId,
                    'jid' => (string) $jid,
                    'message' => $text,
                ]);

            $conversation->messages()->create([
                'direction' => 'outgoing',
                'message' => $text,
                'payload' => ['source' => 'application_handler_ack'],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ApplicationHandler instant ack failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function reply(WhatsappConversation $conversation, string $reply): array
    {
        $conversation->messages()->create([
            'direction' => 'outgoing',
            'message' => $reply,
            'payload' => [
                'source' => 'application_handler',
            ],
        ]);

        return [
            'handled' => true,
            'type' => 'text',
            'reply' => $reply,
            'image' => null,
            'images' => [],
            'image_items' => [],
            'image_groups' => [],
        ];
    }

    private function machineDisplayName(Machine $machine): string
    {
        $brand = trim((string) ($machine->brand?->name ?? ''));
        $name = trim((string) $machine->name);

        return $brand && !str_contains(mb_strtolower($name), mb_strtolower($brand))
            ? $brand . ' ' . $name
            : $name;
    }
}
