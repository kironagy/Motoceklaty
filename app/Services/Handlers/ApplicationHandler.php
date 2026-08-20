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

        if (($conversation->pending_question ?? null) === 'application_documents') {
            return $this->reply($conversation, $this->documentPrompt($payload));
        }

        $blocked = $this->blockedByExistingRequest($conversation);

        if ($blocked) {
            return $this->reply($conversation, $blocked);
        }

        $machine = $this->currentMachine($conversation);

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
         * منسألوش تاني "كاش ولا تقسيط؟" ومنستخدمش عدد الشهور اللي حسبه.
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

        $application = $this->mergeApplicationData(
            $application,
            $analysis['application_data'] ?? []
        );

        if (empty($application['income_proof']) && $this->messageDeniesIncomeProof($message)) {
            $application['income_proof'] = 'لا يوجد';
        }

        $missing = $this->missingFields($application);

        if (!empty($missing)) {
            $this->saveState($conversation, $application, $missing, null);

            return $this->reply($conversation, $this->questionForMissing($missing, $application));
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

    private function categorizeIncome(string $jobType, string $incomeProof): string
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

    private function missingFields(array $data): array
    {
        $required = [
            'full_name',
            'national_id',
            'phone',
            'job_type',
            'income_proof',
            'work_address',
            'home_address',
            'installment_months',
        ];

        return array_values(array_filter($required, fn ($key) => empty($data[$key])));
    }

    private function questionForMissing(array $missing, array $application): string
    {
        $labels = [
            'full_name' => 'الاسم بالكامل',
            'national_id' => 'الرقم القومي',
            'phone' => 'رقم الموبايل',
            'job_type' => 'طبيعة شغلك',
            'income_proof' => 'إثبات دخل (مفردات مرتب أو غيرها لو متاح)',
            'work_address' => 'عنوان الشغل بالتفصيل',
            'home_address' => 'عنوان السكن بالتفصيل',
            'installment_months' => 'مدة التقسيط اللي تحبها',
        ];

        $items = array_map(fn ($key) => $labels[$key] ?? $key, $missing);

        if (count($items) === 1) {
            return 'تمام يا فندم، ناقصني ' . $items[0] . ' عشان أكمل طلب التقديم.';
        }

        $list = implode("\n", array_map(fn ($item) => '- ' . $item, $items));

        return "تمام يا فندم، ناقصني البيانات دي عشان أكمل طلب التقديم:\n{$list}";
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
        ?string $pendingQuestion
    ): void {
        $payload = $this->payload($conversation);

        $payload['application'] = $application;
        $payload['missing_fields'] = $missing;

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

            $url = rtrim((string) env('WHATSAPP_WORKER_URL', 'http://127.0.0.1:3010'), '/') . '/send-message';

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
