<?php

namespace App\Services\Handlers;

use App\Models\InstallmentRequest;
use App\Models\Machine;
use App\Models\WhatsappConversation;
use App\Services\AiComplexReplyService;
use App\Services\AiMemoryResolver;
use App\Services\AiMemoryRules;
use App\Services\DocumentDataExtractor;
use App\Services\Ocr\OcrProviderInterface;
use Illuminate\Support\Facades\Storage;

class ApplicationHandler
{
    /*
     * Injected by the container in normal use; constructed on demand when
     * this handler is built by hand (unit tests). AiMemoryRules degrades to
     * an empty rule set without a database, so either way the built-in
     * lists below are what answers.
     */
    public function __construct(private ?AiMemoryRules $memoryRules = null)
    {
    }

    private function memoryRules(): AiMemoryRules
    {
        return $this->memoryRules ??= new AiMemoryRules();
    }

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
            return $this->reply($conversation, $this->documentStageReply($conversation, $payload, $message));
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

        /*
         * لو الدور اللي فات كان رافض الاسم ولسه مستني تصحيحه، فالرسالة
         * دي هي الرد على السؤال ده. الاستخراج (نداء LLM) رجّع null
         * للاسم أكتر من مرة في محادثة 254 رغم إن العميل باعت اسم رباعي
         * سليم - فبنقراه هنا قراءة تركيبية من غير نداء، عشان نداء واحد
         * بيتلخبط ميكلّفش العميل دورة كاملة تانية. القرار إن ده اسم
         * حقيقي لسه بتاع ApplicantDataVerifier زي ما هو.
         */
        if (
            empty($extracted['full_name'])
            && empty($application['full_name'])
            && ! empty($application['full_name_issue'])
        ) {
            $recovered = app(\App\Services\ApplicantNameValidator::class)->recoverNameAnswer($message);

            if ($recovered !== null) {
                $extracted['full_name'] = $recovered;
            }
        }

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
        /*
         * أول حاجة تتفحص بمجرد ما نعرف الوظيفة: الفئات الممنوعة. من غير
         * كده العميل الممنوع بيكمّل كل الأسئلة والمستندات وبعدين يترفض يدوي.
         */
        $jobType = trim((string) ($application['job_type'] ?? ''));

        if ($jobType !== '' && ($banReason = $this->bannedProfessionReason($jobType)) !== null) {
            return $this->reply($conversation, $banReason);
        }

        /*
         * التحقق من الهوية (الاسم بالكامل، الرقم القومي، واقعية العنوان)
         * لازم يجري هنا بالظبط: بعد ما الاستخراج دمج القيم الجديدة، وقبل
         * refreshAddressComponents وmissingFields - عشان أي قيمة اترفضت
         * تتشال من الحقل فتتحسب "لسه ناقصة" في نفس الدورة، مش تعدي
         * كمكتملة وتترفض يدويًا في الآخر.
         */
        $verification = app(\App\Services\ApplicantDataVerifier::class)->verify($application);
        $application = $verification['application'];

        /*
         * السن بره حدود التمويل (من الرقم القومي نفسه) وقفة نهائية زي
         * الوظيفة الممنوعة بالظبط - مفيش مستند بعد كده ممكن يغيّرها، فقوله
         * دلوقتي بدل ما يرفع كل الأوراق ويترفض.
         */
        if (($verification['blocking_message'] ?? null) !== null) {
            /*
             * Was saving missing=[] and pending_question=null, which ended
             * the application outright - so even a customer who simply
             * mistyped a digit had no way back into the flow, and the next
             * message was handled as if no application had ever started
             * (conversation 252). Keep the collected data and the real
             * missing list so a corrected answer resumes normally; the
             * blocking message itself already says what the problem is.
             */
            $application = $stateService->refreshAddressComponents($application);

            $blockedCategory = $this->categorizeIncome(
                (string) ($application['job_type'] ?? ''),
                (string) ($application['income_proof'] ?? '')
            );

            $stillMissing = $stateService->missingFields(
                $application,
                $blockedCategory === 'freelance',
                $this->requiresVehicleAnswer($blockedCategory)
            );

            $this->saveState($conversation, $application, $stillMissing, null, [], $payload);

            return $this->reply($conversation, $verification['blocking_message']);
        }

        $verificationIssues = $verification['issues'] ?? [];

        /*
         * لازم نقارن بالقيمة قبل الدور ده (من $payload، قبل الدمج)، مش
         * بـ $application['income_proof'] بعد الدمج - لإن AiIntentClassifier
         * نفسه (prompt خط 443) بيحط "لا يوجد" مباشرة جوه application_data
         * لما يكتشف مهنة حرة، يعني بيوصل هنا already-non-empty قبل حتى ما
         * نوصل للـ fallback تحت. لو اعتمدنا على القيمة بعد الدمج، كنا
         * هنفوّت بالظبط الحالة دي ونعتبرها "استلمت فعليًا".
         */
        /*
         * Plan task 3.5: name and job survive the conversation from here on,
         * so a customer who comes back next week is not asked again.
         */
        app(\App\Services\CustomerProfileService::class)->rememberApplication(
            $conversation,
            $application,
            $this->categorizeIncome($jobType, (string) ($application['income_proof'] ?? ''))
        );

        $incomeProofWasEmpty = empty($payload['application']['income_proof'] ?? null);

        if (empty($application['income_proof'])) {
            if ($this->messageDeniesIncomeProof($message) || $this->categorizeIncome((string) ($application['job_type'] ?? ''), '') === 'freelance') {
                $application['income_proof'] = 'لا يوجد';
            }
        }

        $incomeProofExempted = $incomeProofWasEmpty && ($application['income_proof'] ?? null) === 'لا يوجد';

        /*
         * A courier or an app driver has no fixed workplace by definition -
         * the job IS moving around. Waiting for them to explicitly deny
         * having one (which is all that set this sentinel before) meant the
         * bot kept demanding "عنوان الشغل بالتفصيل" from someone who has no
         * such address, and the application could not finish (conversation
         * 252). Treat the category itself as the answer, exactly the way
         * income_proof is already exempted for freelance above.
         */
        if (empty($application['work_address'])) {
            $currentCategoryForWorkplace = $this->categorizeIncome(
                (string) ($application['job_type'] ?? ''),
                (string) ($application['income_proof'] ?? '')
            );

            if (in_array($currentCategoryForWorkplace, ['delivery', 'taxi_owner'], true)) {
                $application['work_address'] = \App\Services\ApplicationStateService::NO_WORKPLACE;
            }
        }

        /*
         * فئة الشغل ممكن تتغيّر مش بس تتعرف أول مرة - العميل قال "معاش"
         * وبعدين غيّر لـ"سباك عمل حر" في نفس المحادثة. لازم رسالة
         * المتطلبات تتحدّث كل مرة الفئة تتغيّر، مش بس أول مرة job_type
         * يتملى من فاضي.
         */
        $previousJobType = trim((string) ($payload['application']['job_type'] ?? ''));
        $previousCategory = $previousJobType !== ''
            ? $this->categorizeIncome($previousJobType, (string) ($payload['application']['income_proof'] ?? ''))
            : null;

        $application = $stateService->refreshAddressComponents($application);

        $incomeCategory = $this->categorizeIncome(
            (string) ($application['job_type'] ?? ''),
            (string) ($application['income_proof'] ?? '')
        );

        $isFreelance = $incomeCategory === 'freelance';

        /*
         * الدليفري وسواقين التطبيقات: المطلوب منهم بيختلف جذريًا حسب
         * المركبة - اللي على عجلة مش هيتطلب منه رخصة أصلاً (وطلبها منه
         * بيوقّف طلبه على حاجة مش موجودة)، واللي على موتوسيكل أو عربية
         * لازم رخصة سارية باسمه. فلازم نعرف المركبة قبل ما نبدأ نطلب
         * مستندات، مش بعد كده.
         */
        $application['work_vehicle'] = $this->normalizeVehicle($application['work_vehicle'] ?? null);

        /*
         * الرد على سؤال المركبة بيبقى كلمة واحدة ("موتوسيكل") والاستخراج
         * أحيانًا بيسيبها. بنقراها من نص الرسالة نفسها بس لما نكون فعلاً
         * سألنا عنها في الدور اللي فات - مش في أي رسالة، لأن العميل بيقول
         * "عايز أقسط موتوسيكل" وهو بيتكلم عن المكنة اللي بيشتريها مش عن
         * اللي بيشتغل عليها دلوقتي.
         */
        if ($application['work_vehicle'] === null && in_array('work_vehicle', $payload['missing_fields'] ?? [], true)) {
            $application['work_vehicle'] = $this->normalizeVehicle($message);
        }

        $requiresVehicle = $this->requiresVehicleAnswer($incomeCategory);

        $previousMissing = $payload['missing_fields'] ?? [];
        $missing = $stateService->missingFields($application, $isFreelance, $requiresVehicle);

        if (!empty($missing)) {
            /*
             * اللي كان ناقص قبل وبقى موجود دلوقتي - من غيرها كل رد كان
             * بيرجع نفس القالب الثابت "ناقصني البيانات دي" تاني من غير
             * ما يقول إنه فعلاً استلم اللي العميل بعته، وده كان بيحس إن
             * البوت مش قاري كلامه أصلاً.
             */
            $newlyFilled = array_values(array_diff($previousMissing, $missing));

            $currentJobType = trim((string) ($application['job_type'] ?? ''));
            $currentCategory = $currentJobType !== ''
                ? $this->categorizeIncome($currentJobType, (string) ($application['income_proof'] ?? ''))
                : null;

            /*
             * متطلبات الدليفري بتتفرع حسب المركبة (عجلة = مفيش رخصة
             * أصلاً)، فالرسالة دي بتستنى إجابة work_vehicle زي ما الحقل
             * نفسه بيستناها - راجع ApplicationStateService::shouldSendCategoryNote().
             *
             * ولإنها ممكن تتأجل كده، شرط "الفئة اتغيّرت" لوحده مش كفاية:
             * لما المركبة توصل في الدور اللي بعده الفئة بتكون هي هي،
             * فكانت الرسالة هتضيع خالص. بنفتكر إحنا بعتناها لأنهي فئة
             * بدل ما نقارن بالدور اللي فات بس.
             */
            $noteSentForCategory = $payload['category_note_sent_for'] ?? null;

            $categoryNote = (
                $currentCategory !== null
                && $currentCategory !== $noteSentForCategory
                && $stateService->shouldSendCategoryNote($currentCategory, $application)
            )
                ? $this->categoryRequirementsNote($currentCategory)
                : null;

            /*
             * income_proof بيختفي من الناقص لما الفئة تتحدد "دخل حر" (أو
             * العميل رفض يبعته)، مش لإنه فعلاً بعت حاجة - lines أعلى بتحط
             * "لا يوجد" تلقائيًا. قول "استلمت إثبات الدخل" هنا كذب صريح؛
             * categoryRequirementsNote() فوق بيوضح الصح بدل منه.
             */
            if ($incomeProofExempted && in_array('income_proof', $newlyFilled, true)) {
                $newlyFilled = array_values(array_diff($newlyFilled, ['income_proof']));
            }

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
                array_filter([
                    'no_progress_streak' => $noProgressStreak,
                    'category_note_sent_for' => $categoryNote !== null
                        ? $currentCategory
                        : $noteSentForCategory,
                ], fn ($value) => $value !== null),
                $payload
            );

            /*
             * أصحاب المعاشات لسه محتاجين "إثبات دخل" فعليًا (بيان معاش)،
             * مش معفيين زي أصحاب المهن الحرة - بس اللابل العام "مفردات
             * مرتب" بتاع الموظفين مش منطقي ليهم ويناقض رسالة categoryNote
             * اللي فوق. لابل مخصص بدل العام لما الفئة الحالية معاش.
             */
            $labelOverrides = $currentCategory === 'pension'
                ? ['income_proof' => 'بيان معاش حديث (مختوم وأقل من شهر)']
                : [];

            /*
             * الحقول اللي التحقق رفضها (اسم ثنائي، رقم قومي مش بيفك،
             * عنوان مش حقيقي) بتترجع في $missing لأنها اتفضّت - بس سؤالها
             * الصح هو رسالة السبب المحددة، مش سطر "ناقصني الاسم بالكامل"
             * العام اللي العميل شايف إنه بعته خلاص.
             *
             * وطول ما فيه رفض مفتوح، الرفض ده هو سؤال الدور كله - مش
             * بنسأل الحقل اللي بعده معاه. ده كان بالظبط اللي كسر محادثة
             * 254: الدور اللي رفض "احمد سيد" قفل بسؤال عن الرقم القومي،
             * فالاسم الصح اللي رجع بعده اتقرا في مقابل السؤال الغلط
             * واتضاع. راجع ApplicationStateService::fieldsToAsk().
             */
            $missingForQuestion = $stateService->fieldsToAsk($missing, $verificationIssues);

            $reply = ! empty($missingForQuestion)
                ? $stateService->questionForMissing($missingForQuestion, $application, $newlyFilled, $noProgressStreak, $labelOverrides, $hasAskedBefore)
                : '';

            if (! empty($verificationIssues)) {
                $issuesText = count($verificationIssues) === 1
                    ? reset($verificationIssues)
                    : implode("\n\n", array_map(fn ($issue) => "• {$issue}", $verificationIssues));

                $reply = $reply !== '' ? "{$issuesText}\n\n{$reply}" : $issuesText;
            }

            if ($categoryNote !== null) {
                $reply = "{$categoryNote}\n\n{$reply}";
            }

            return $this->reply($conversation, $reply);
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
                /*
                 * "أهم حاجة تكون باسمه" - المستند لازم يتقارن باسم مقدّم
                 * الطلب نفسه، فالاسم بيتبعت مع نص الـ OCR بدل ما التحقق
                 * يفضل على وضوح الصورة بس. الرقم القومي معاه عشان البطاقة
                 * تتقارن برقم كمان مش بالاسم لوحده.
                 */
                'expected_name' => $payload['application']['full_name'] ?? null,
                'expected_national_id' => $payload['application']['national_id'] ?? null,
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
        unset($payload['document_prompt_repeat_streak']);

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
            'notes' => $this->applicationNotes($application),
        ];

        /*
         * السن اتحسب من الرقم القومي نفسه وقت ما العميل كتبه (مش مستني
         * OCR البطاقة)، فالطلب بيوصل للموظف وتاريخ الميلاد وعلامة السن
         * مليانين فعلاً بدل ما يفضلوا فاضيين أو مليانين true على أعمى.
         */
        if (! empty($application['birthdate'])) {
            $attributes['applicant_birthdate'] = $application['birthdate'];
            $attributes['applicant_age_ok'] = (bool) ($application['age_ok'] ?? false);
        }

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
            foreach ($this->mapDocumentToAttributes($docType, $doc) as $key => $value) {
                /*
                 * أكتر من مستند ممكن يروحوا لنفس العمود (رخصة القيادة +
                 * سكرين الرحلات الاتنين بيتحفظوا في free_income_proof_images)
                 * - array_merge على المستوى ده كان بيخلي الأخير يمسح
                 * اللي قبله، فصورة كاملة كانت بتضيع من الطلب.
                 */
                if (is_array($value) && is_array($documentAttributes[$key] ?? null)) {
                    $documentAttributes[$key] = array_merge($documentAttributes[$key], $value);

                    continue;
                }

                $documentAttributes[$key] = $value;
            }
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

        app(\App\Services\CustomerProfileService::class)->recordSubmittedApplication($conversation);
    }

    private function mapDocumentToAttributes(string $docType, array $doc): array
    {
        $fields = $doc['fields'] ?? [];
        $path = $doc['path'] ?? null;

        return match ($docType) {
            /*
             * applicant_age_ok كانت متحطوطة true ثابتة هنا لمجرد إن صورة
             * بطاقة وصلت - يعني كل طلب بيوصل للموظف وعلامة "السن مظبوط"
             * مرفوعة من غير ما حد يحسب السن أصلاً. السن دلوقتي بيتحسب من
             * الرقم القومي في createInstallmentRequest()، والمستند بيسيب
             * الخانة دي لصاحبها.
             */
            'id_card_front' => [
                'applicant_id_image' => $path,
                'applicant_national_id' => $fields['national_id'] ?? null,
                'applicant_name' => $fields['name'] ?? $fields['full_name'] ?? null,
                'applicant_birthdate' => $fields['birthdate'] ?? null,
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
            /*
             * مفيش أعمدة مخصوصة للرخص في installment_requests، وأقرب عمود
             * صح هو free_income_proof_images (array) - نوع كل صورة موصوف
             * في notes وفي documents_collected بالمفتاح بتاعه.
             */
            'driver_license', 'vehicle_license', 'trips_screenshot' => [
                'free_income_proof_images' => [$path],
            ],
            default => [],
        };
    }

    /**
     * Which income categories cannot have their document list decided
     * without knowing what the customer actually rides. Delivery is the
     * whole reason this exists: a courier on a bicycle has no licence to
     * send, one on a motorcycle must send a valid one, and asking the
     * first for the second stalls the application on a document that does
     * not exist.
     */
    public function requiresVehicleAnswer(string $category): bool
    {
        return $category === 'delivery';
    }

    /**
     * Folds every wording customers use onto the three vehicle classes the
     * document list branches on. Returns null for anything it does not
     * recognise - guessing here is worse than asking, because a wrong
     * guess silently changes which documents the customer is required to
     * produce.
     */
    public function normalizeVehicle(?string $raw): ?string
    {
        $text = $this->normalizeJobText((string) $raw);

        if ($text === '') {
            return null;
        }

        if (in_array($text, ['bicycle', 'motorcycle', 'car'], true)) {
            return $text;
        }

        if ($this->containsAny($text, ['عجل', 'بسكلت', 'بيسكلت', 'دراجه هوائيه'])) {
            return 'bicycle';
        }

        if ($this->containsAny($text, ['موتوسيكل', 'موتسيكل', 'موتور', 'سكوتر', 'اسكوتر', 'تروسيكل', 'دراجه ناريه'])) {
            return 'motorcycle';
        }

        if ($this->containsAny($text, ['عربيه', 'عربية', 'ملاكي', 'سياره', 'تاكسي', 'اوبر', 'uber', 'كريم', 'careem', 'ديدي', 'didi', 'اندرايف', 'indrive'])) {
            return 'car';
        }

        return null;
    }

    private function requiredDocuments(array $application): array
    {
        $category = $this->categorizeIncome(
            (string) ($application['job_type'] ?? ''),
            (string) ($application['income_proof'] ?? '')
        );

        $base = ['id_card_front', 'id_card_back'];

        /*
         * المطلوب من الدليفري بيتحدد بالمركبة، مش بالفئة لوحدها:
         *  - عجلة: سكرين من تطبيق الشغل بس (مفيش رخصة أصلاً).
         *  - موتوسيكل: سكرين + رخصة قيادة سارية.
         *  - عربية (أوبر/كريم/إنdrive): البطاقة (موجودة في base) + سكرين
         *    + رخصة سارية - وكلهم لازم يكونوا باسم العميل نفسه، وده
         *    بيتفحص بالـ OCR في handleDocument().
         * لو المركبة لسه مش معروفة، missingFields() مكانش يخلّينا نوصل
         * هنا أصلاً - السطر ده احتياطي بيطلب أوسع مجموعة.
         */
        $deliveryDocuments = match ($this->normalizeVehicle($application['work_vehicle'] ?? null)) {
            'bicycle' => ['trips_screenshot'],
            'motorcycle', 'car' => ['trips_screenshot', 'driver_license'],
            default => ['trips_screenshot', 'driver_license'],
        };

        $categorySpecific = match ($category) {
            'pension' => ['pension_statement'],
            'business' => ['activity_photo'],
            'army' => ['bank_statement'],
            'delivery' => $deliveryDocuments,
            // ميموري «التاكسي»: رخصة شخصية + رخصة التاكسي (لصاحب التاكسي).
            'taxi_owner' => ['driver_license', 'vehicle_license'],
            'freelance' => [],
            default => ['salary_slip'],
        };

        // A memory's rules block may redefine what its own category needs.
        $fromMemory = $this->memoryRules()->requiredDocumentsFor($category);

        return array_merge($base, $fromMemory ?? $categorySpecific);
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
            // سواقين التطبيقات وأصحاب التاكسي دخلهم غير ثابت - نفس خانة
            // العمل الحر في الداشبورد، بس بمستندات مخصوصة.
            'delivery', 'taxi_owner' => 'self_employed',
            'army' => 'employee',
            default => $hasNoIncomeProof ? 'no_income_proof' : 'employee',
        };
    }

    public function categorizeIncome(string $jobType, string $incomeProof): string
    {
        $text = mb_strtolower($jobType . ' ' . $incomeProof);

        /*
         * Plan task 3.3: a memory can teach a new job category (or new
         * wording for an existing one) without a deploy. Checked first so a
         * hand-added category wins over the generic freelance catch-all
         * below, which is what would otherwise swallow it.
         */
        foreach ($this->memoryRules()->jobCategoryKeywords() as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && str_contains($text, mb_strtolower($keyword))) {
                    return $category;
                }
            }
        }

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

        /*
         * ميموري «الدليفري» و«التاكسي»: الفئتين دول مطلوب منهم مستندات
         * مختلفة تمامًا عن باقي العمل الحر (رخصة سارية + سكرين رحلات /
         * رخصة المركبة)، وقبل كده كانوا بيتصنفوا freelance فيتطلب منهم
         * البطاقة بس ويتكشف الناقص بعدين من الموظف.
         */
        if (
            str_contains($text, 'تاكسي') || str_contains($text, 'تاكس')
            || str_contains($text, 'ميكروباص') || str_contains($text, 'ميكروباس')
        ) {
            return 'taxi_owner';
        }

        if (
            str_contains($text, 'دليفري') || str_contains($text, 'ديليفري')
            || str_contains($text, 'طلبات') || str_contains($text, 'اوبر')
            || str_contains($text, 'أوبر') || str_contains($text, 'uber')
            || str_contains($text, 'اندرايف') || str_contains($text, 'indrive')
            || str_contains($text, 'مرسول') || str_contains($text, 'بوسطجي')
            /*
             * "مندوب توصيل/سواق تطبيقات" is the exact literal the
             * extraction prompt in AiIntentClassifier is told to write for
             * this category - and none of the keywords above matched it, so
             * it fell through to the generic freelance branch below on the
             * word "سواق". Result: a courier was never asked which vehicle
             * he rides and never got the delivery document list (seen live
             * in conversation 252).
             */
            || str_contains($text, 'توصيل') || str_contains($text, 'تطبيقات')
            || str_contains($text, 'كريم') || str_contains($text, 'careem')
            || str_contains($text, 'طيار')
        ) {
            return 'delivery';
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


    /**
     * ai_memories "الفئات الممنوعة" (#51, scope=always_include) lists jobs
     * the shop never finances at all - police/interior ranks, lawyers, and
     * the judiciary. That memory only ever reached the AI fallback path;
     * nothing gated on it here, so an excluded applicant could upload every
     * document and only get rejected at the very end. Checked as soon as
     * job_type is known, before any further questions are asked.
     *
     * Keywords are hardcoded (not parsed from the memory text) to keep this
     * a plain, fast, reviewable check - matches how categorizeIncome() above
     * already works. Matching runs on normalizeJobText() output so the many
     * spellings of the same job ("أمين الشرطة" / "امين شرطه", "محامى" /
     * "محاماة") all fold onto one form.
     */
    public function bannedProfessionReason(string $jobType): ?string
    {
        $text = $this->normalizeJobText($jobType);

        /*
         * Interior-ministry ranks. The memory lists them bare (ضابط / أمين /
         * معاون) but a bare أمين or معاون is ambiguous in Egyptian job
         * wording (أمين مخزن, معاون مدير), so those two only count when the
         * message also places the customer inside the ministry. ضابط has no
         * such civilian meaning here.
         */
        $interiorContext = $this->containsAny($text, [
            'شرطه', 'مباحث', 'داخليه', 'وزاره داخليه', 'امن مركزي', 'سجون', 'مرور',
        ]);

        $isBanned =
            $this->containsAny($text, ['ضابط', 'ظابط'])
            || ($interiorContext && $this->containsAny($text, ['امين', 'معاون', 'فرد', 'مجند', 'عسكري']))
            // Lawyers: محامي / محامى / محاماه / محاماة all normalize onto محام.
            || $this->containsAny($text, ['محام'])
            // Judiciary: judges, prosecution, court officials.
            || $this->containsAny($text, ['قاضي', 'قضاء', 'قضائي', 'نيابه', 'محكمه', 'مستشار قضائي']);

        /*
         * Plan task 3.3: anything staff added to the «الفئات الممنوعة»
         * memory's rules block counts too. Additive only - the list above is
         * a floor a mistyped rules block cannot lower.
         */
        if (! $isBanned) {
            foreach ($this->memoryRules()->bannedProfessions() as $keyword) {
                $keyword = $this->normalizeJobText($keyword);

                if ($keyword !== '' && str_contains($text, $keyword)) {
                    $isBanned = true;

                    break;
                }
            }
        }

        if (! $isBanned) {
            return null;
        }

        return 'للأسف يا فندم، نظام التقسيط عندنا مش متاح لوظيفتك حاليًا. تحب تعرف تفاصيل الشراء كاش؟';
    }

    /**
     * Same Arabic folding the router uses before any keyword match: hamza
     * forms onto ا, ة onto ه, ى onto ي, and the definite article stripped -
     * so "أمين الشرطة" and "امين شرطه" are one string here.
     */
    private function normalizeJobText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace('ى', 'ي', $text);
        $text = preg_replace('/\bال(?=[\p{Arabic}]{2,})/u', '', $text);
        $text = preg_replace('/[^\p{Arabic}a-zA-Z0-9\s]/u', ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * أول مرة نعرف فئة شغل العميل (job_type اتملى في نفس الدور ده)، بنقوله
     * على طول إيه المطلوب منه فعليًا حسب فئته - بدل ما يفضل يكتشف كل بند
     * لوحده على مدار كذا رسالة. لو الفئة "army" مفيش ميموري مخصصة لها
     * حاليًا فبيرجع null (السلوك القديم كما هو).
     */
    private function categoryRequirementsNote(string $category): ?string
    {
        $titles = [
            'employee' => 'رد متطلبات الموظف',
            'freelance' => 'رد متطلبات العمل الحر',
            'business' => 'رد متطلبات صاحب المشروع',
            'pension' => 'رد متطلبات المعاش',
            'delivery' => 'الدليفري',
            'taxi_owner' => 'التاكسي',
        ];

        $title = $titles[$category] ?? null;

        if ($title === null) {
            return null;
        }

        $memory = app(AiMemoryResolver::class)->memoryByExactTitle($title);

        if (! $memory) {
            return null;
        }

        $reply = trim((string) app(\App\Services\AiReplyBuilder::class)->fromMemories(collect([$memory]), [], ''));

        return $reply !== '' ? $reply : null;
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
            'driver_license' => ['الدليفري', 'التاكسي'],
            'trips_screenshot' => ['الدليفري'],
            'vehicle_license' => ['التاكسي'],
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

    /**
     * Free-text note on the request. Beyond the job wording, this is where
     * anything the automated checks could not settle gets surfaced: a name
     * or an address that stayed unverified after MAX_ATTEMPTS is accepted
     * so the customer is never trapped, but the reviewer has to be told
     * that it was accepted rather than verified - otherwise "the bot let it
     * through" reads as "the bot checked it".
     */
    private function applicationNotes(array $application): string
    {
        $lines = ['طبيعة الشغل من كلام العميل: ' . ($application['job_type'] ?? '-')];

        if (! empty($application['age'])) {
            $lines[] = 'السن من الرقم القومي: ' . $application['age'] . ' سنة'
                . (($application['age_ok'] ?? false) ? '' : ' (بره حدود التمويل)');
        }

        $reviewLabels = [
            'full_name_needs_review' => 'الاسم اتقبل من غير ما يتأكد إنه اسم كامل - راجعه مع البطاقة',
            'home_address_needs_review' => 'عنوان السكن مش متأكدين إنه مكان معروف - محتاج مراجعة',
            'work_address_needs_review' => 'عنوان الشغل مش متأكدين إنه مكان معروف - محتاج مراجعة',
        ];

        foreach ($reviewLabels as $flag => $label) {
            if (! empty($application[$flag])) {
                $lines[] = '⚠️ ' . $label;
            }
        }

        return implode("\n", $lines);
    }

    private function documentLabel(string $docType): string
    {
        return match ($docType) {
            'id_card_front' => 'صورة البطاقة (وش)',
            'id_card_back' => 'صورة البطاقة (ضهر)',
            'salary_slip' => 'مفردات المرتب (لازم تكون باسم حضرتك)',
            'pension_statement' => 'بيان المعاش (لازم يكون باسم حضرتك)',
            'activity_photo' => 'صورة النشاط/المحل',
            'bank_statement' => 'كشف الحساب لآخر 6 شهور (باسم حضرتك)',
            'driver_license' => 'رخصة القيادة - لازم تكون سارية وباسم حضرتك',
            'trips_screenshot' => 'سكرين من تطبيق الشغل يبيّن الحساب والرحلات - لازم يكون حساب حضرتك',
            'vehicle_license' => 'رخصة التاكسي/المركبة - لازم تكون سارية وباسم حضرتك',
            default => 'المستند المطلوب',
        };
    }

    /**
     * The old behaviour returned documentPrompt() byte-identical for any
     * text sent while waiting on a document ("هبعتها بكرة", "معنديش
     * سكانر", "شكراً"...), which read as the most literal "bot, not a
     * person" moment in the product (audit §8.4). A genuine question
     * ("ليه محتاجين ده؟"/"ينفع صورة من الموبايل؟") now gets a real answer
     * from the free-reply model, focused on the document step, before the
     * prompt is repeated; anything else still gets the same request but
     * with a rotating opener instead of a flat repeat.
     */
    private function documentStageReply(WhatsappConversation $conversation, array $payload, string $message): string
    {
        $prompt = $this->documentPrompt($payload);
        $trimmed = trim($message);

        $looksLikeQuestion = $trimmed !== '' && (
            str_contains($trimmed, '؟')
            || str_contains($trimmed, '?')
            || preg_match('/(ليه|ازاي|إزاي|امتى|إمتى|فين|ينفع|لازم|ممكن|هل)/u', $trimmed) === 1
        );

        if ($looksLikeQuestion) {
            try {
                $result = app(AiComplexReplyService::class)->reply($message, [
                    'conversation_id' => $conversation->id,
                    'intent' => 'application',
                    'step_focus' => 'سؤاله عن المستند بس، من غير ما تطلب منه معلومة تانية غير المستند المطلوب حاليًا',
                    'messages' => $conversation->messages()->latest()->take(10)->get()->reverse()
                        ->map(fn ($m) => ['direction' => $m->direction, 'message' => $m->message])->values()->all(),
                    'current_message' => $message,
                ]);

                if (($result['ok'] ?? false) && trim((string) ($result['reply'] ?? '')) !== '') {
                    return trim($result['reply']) . "\n\n" . $prompt;
                }
            } catch (\Throwable $e) {
                // Fall through to the plain prompt below - never break the flow.
            }
        }

        $streak = (int) ($payload['document_prompt_repeat_streak'] ?? 0) + 1;

        $payload['document_prompt_repeat_streak'] = $streak;
        $conversation->forceFill(['context_payload' => $payload])->save();

        if ($streak > 1) {
            $openers = [
                'لسه مستنى المستند ده يا فندم:',
                'ولسه محتاج منك:',
                'تمام، بس لسه ناقصني:',
            ];

            $prompt = $openers[$streak % count($openers)] . ' ' . $prompt;
        }

        return $prompt;
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
