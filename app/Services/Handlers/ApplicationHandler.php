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
            /*
             * القايمة المتصلّحة لازم تتحفظ، مش تتصلّح في الذاكرة وترجع في
             * الرد بس - documentStageReply بياخد $payload بالقيمة، فمن غير
             * الحفظ ده كل دور جاي كان بيقرا نفس القايمة الغلط من الداتابيز
             * تاني (والفروع اللي بتحفظ جواه ممكن ما تتنفذش أصلاً).
             */
            if ($this->enforceBicycleHasNoLicence($conversation, $payload)) {
                $conversation->forceFill(['context_payload' => $payload])->save();
            }

            return $this->reply($conversation, $this->documentStageReply($conversation, $payload, $message));
        }

        $blocked = $this->blockedByExistingRequest($conversation);

        if ($blocked) {
            return $this->reply($conversation, $blocked);
        }

        $application = $payload['application'] ?? [];

        if (!$machine) {
            /*
             * كان بيرجع من هنا على طول. لو العميل بعت اسمه وسنه وشغله
             * وعنوانه في نفس الرسالة (وده اللي بيحصل فعلًا لما نسأله
             * سؤال مفتوح)، كل ده كان بيتمسح، وبعد ما يختار المكنة كنا
             * بنطلب منه الـ ٨ بيانات من الأول تاني. دلوقتي بنبنّك أي
             * بيانات في الرسالة قبل ما نسأل عن المكنة.
             */
            $application = $this->bankVolunteeredData($conversation, $application, $message, null);

            $payload['application'] = $application;
            $conversation->forceFill([
                'last_topic' => 'application',
                'context_payload' => $payload,
            ])->save();

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
            /*
             * نفس مشكلة الفرع اللي فوق: الرجوع من هنا من غير استخراج كان
             * بيرمي أي بيانات في نفس الرسالة. بنبنّكها الأول، وبعدين
             * نسأل عن طريقة الدفع.
             */
            $application = $this->bankVolunteeredData($conversation, $application, $message, $machine);

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

        /*
         * نفس فكرة recoverNameAnswer بالظبط للرقم القومي: الـ LLM بيفوّت
         * الرقم أحيانًا لما تيجي رسالة فيها نص مصاحب زي "ده الرقم القومي
         * اهو 30410012208373" أو لما العميل يبعت الرقم مرة تانية بعد سؤال.
         * بنعمل regex scan على نص الرسالة الخام: أي تسلسل 14 رقم متتالي
         * مش بادئ بـ01 (ده رقم موبايل) يتحسب رقم قومي محتمل ويتسند للـ
         * extracted لو الـ AI سابه فاضي.
         *
         * بنستخدم normalizeDigits لتحويل الأرقام العربية قبل البحث.
         */
        if (empty($extracted['national_id']) && empty($application['national_id'])) {
            $nationalIdSupport = app(\App\Support\EgyptianNationalId::class);
            $normalizedMsg    = $nationalIdSupport->normalizeDigits($message);

            if (preg_match('/(?<!\d)(\d{14})(?!\d)/', $normalizedMsg, $m)) {
                $candidate = $m[1];

                if (! str_starts_with($candidate, '01')) {
                    $extracted['national_id'] = $candidate;
                }
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

        /*
         * الاستخراج بالـ LLM بيهلوس ساعات في نص العنوان - بيرجّع تفاصيل
         * (رقم عمارة، دور، شقة، علامة مميزة) العميل ماكتبهاش خالص. لازم
         * نعرف *قبل* الدمج هل الموديل غيّر الحقل ده فعلاً، عشان بعد
         * الدمج نصحح نص العنوان المخزّن من رسالة العميل الخام نفسها -
         * مش من صياغة الموديل.
         */
        $addressFieldsTouchedByAi = array_filter(
            ['work_address', 'home_address'],
            fn ($field) => array_key_exists($field, $extracted)
                && filled($extracted[$field])
                && $extracted[$field] !== ($application[$field] ?? null)
        );

        $extracted = $this->guardStoredWorkVehicle($application, $extracted, $message);

        $application = $this->mergeApplicationData($application, $extracted);

        foreach ($addressFieldsTouchedByAi as $field) {
            $application = $stateService->groundAddressInRawMessage($application, $field, $message);
        }

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
        /*
         * الشرط كان "لو work_address فاضي" بس. المشكلة إن العميل لما
         * يبعت عنوانه في رسالة واحدة مع باقي بياناته، الاستخراج بيحط
         * نفس العنوان في الحقلين (سكن وشغل) - فالحقل مبقاش فاضي،
         * والإعفاء مبيتنفذش، والبوت يفضل يطلب من مندوب التوصيل "رقم
         * العمارة والدور والشقة" بتاع عنوان شغل مش موجود أصلًا.
         *
         * عنوان شغل مطابق حرفيًا لعنوان السكن مش عنوان شغل - ده نفس
         * العنوان اتكرر. بنعامله زي الفاضي بالظبط.
         */
        $workAddress = trim((string) ($application['work_address'] ?? ''));
        $homeAddress = trim((string) ($application['home_address'] ?? ''));
        $workAddressIsEchoOfHome = $workAddress !== ''
            && $workAddress !== \App\Services\ApplicationStateService::NO_WORKPLACE
            && $workAddress === $homeAddress;

        if ($workAddress === '' || $workAddressIsEchoOfHome) {
            $currentCategoryForWorkplace = $this->categorizeIncome(
                (string) ($application['job_type'] ?? ''),
                (string) ($application['income_proof'] ?? '')
            );

            if (in_array($currentCategoryForWorkplace, ['delivery', 'taxi_owner'], true)) {
                $application['work_address'] = \App\Services\ApplicationStateService::NO_WORKPLACE;
                $application['work_address_status'] = 'complete';
                $application['work_address_missing_components'] = [];
                unset($application['work_address_components'], $application['work_address_raw']);
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

        /*
         * الدور اللي فات سألنا عن مكوّن عنوان واحد بالظبط (زي العلامة
         * المميزة)، فالرسالة دي هي الرد عليه. لازم نربطها بالمكوّن ده
         * **قبل** refreshAddressComponents، عشان الـ parser مش بيعرف
         * يستخرج جواب مجرد من غير كلمة مفتاحية - وده كان بيخلي البوت
         * يعيد نفس السؤال بعد ما العميل جاوبه.
         */
        $askedComponent = $payload['asked_address_component'] ?? null;
        $askedField = $payload['asked_address_field'] ?? null;

        if ($askedComponent && $askedField) {
            $application = $stateService->bindAnswerToAskedComponent(
                $application,
                $askedField,
                $askedComponent,
                $message
            );
        } elseif ($askedField) {
            /*
             * سألنا عن أكتر من مكوّن في نفس الحقل ("محتاج رقم العمارة
             * والدور ورقم الشقة وعلامة مميزة")، والعميل رد بجزء من
             * العنوان ("فيلا ١١٥"). لو الاستخراج بالـ LLM رجّع فاضي،
             * الرد كان بيضيع والبوت يعيد نفس السؤال حرفيًا.
             */
            $application = $stateService->absorbAddressAnswer($application, $askedField, $message);
        }

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
        if ($application['work_vehicle'] === null) {
            /*
             * الشرط القديم كان بيقرا المركبة من نص الرسالة بس لو إحنا
             * سألنا عنها في الدور اللي فات. فالعميل اللي بيقول من أول
             * رسالة "أنا شغال طلبات على العجلة" كانت الكلمة "العجلة"
             * بتضيع، والبوت يطلب منه رخصة قيادة (اللي على عجلة مش
             * بتتطلب منه رخصة أصلًا)، وهو يضطر يعيد "أنا على عجلة" تاني.
             *
             * دلوقتي بنقرا من أي رسالة - بس بشرط أمان مهم: كلمات
             * "موتوسيكل/سكوتر/عربية" ممكن تكون بيتكلم بيها عن المكنة
             * اللي بيشتريها مش اللي بيشتغل عليها، فدول مبيتقبلوش إلا
             * لما نكون سألنا فعلًا. "عجلة" ملهاش اللبس ده - إحنا
             * مبنبيعش عجل - فبتتقبل من أي رسالة.
             */
            $weAsked = in_array('work_vehicle', $payload['missing_fields'] ?? [], true);
            $fromMessage = $this->normalizeVehicle($message);

            if ($fromMessage === 'bicycle' || ($fromMessage !== null && $weAsked)) {
                $application['work_vehicle'] = $fromMessage;
            } elseif ($fromMessage !== null && $this->messageStatesCurrentWorkVehicle($message)) {
                $application['work_vehicle'] = $fromMessage;
            }
        }

        /*
         * التصحيح الصريح. الشرط فوق بيقرا المركبة بس لما تكون لسه
         * مش معروفة، فقيمة اتسجلت غلط (من كلمة في رسالة عن المكنة اللي
         * بيشتريها مثلاً) كانت تفضل للأبد والعميل يقول "أنا على عجلة"
         * من غير ما حاجة تتغير. "عجلة" ملهاش لبس - إحنا مبنبيعش عجل -
         * فتصحيحها بيتقبل في أي وقت.
         */
        if ($application['work_vehicle'] !== 'bicycle' && $this->normalizeVehicle($message) === 'bicycle') {
            $application['work_vehicle'] = 'bicycle';
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
                ? $this->categoryRequirementsNote($currentCategory, $application['work_vehicle'] ?? null)
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

            $askedComponentNow = null;
            $askedFieldNow = null;

            $reply = ! empty($missingForQuestion)
                ? $stateService->questionForMissing(
                    $missingForQuestion,
                    $application,
                    $newlyFilled,
                    $noProgressStreak,
                    $labelOverrides,
                    $hasAskedBefore,
                    $askedComponentNow,
                    $askedFieldNow
                )
                : '';

            $this->saveState(
                $conversation,
                $application,
                $missing,
                null,
                array_merge(
                    array_filter([
                        'no_progress_streak' => $noProgressStreak,
                        'category_note_sent_for' => $categoryNote !== null
                            ? $currentCategory
                            : $noteSentForCategory,
                    ], fn ($value) => $value !== null),
                    /*
                     * دول لازم يتكتبوا حتى لو null - عشان لو الدور ده
                     * سأل عن أكتر من حقل (مش مكوّن عنوان واحد)، القيمة
                     * القديمة من دور فات (مثلاً "landmark") تتمسح، وإلا
                     * رد العميل التالي هيتربط غلط بسؤال قديم خلص.
                     */
                    [
                        'asked_address_component' => $askedComponentNow,
                        'asked_address_field' => $askedFieldNow,
                    ]
                ),
                $payload
            );

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

        $this->enforceBicycleHasNoLicence($conversation, $payload);

        $required = $payload['documents_required'] ?? [];
        $index = (int) ($payload['documents_index'] ?? 0);

        if (empty($required) || $index >= count($required)) {
            $conversation->forceFill(['context_payload' => $payload])->save();

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

        /*
         * أول حاجة: نبص على الصورة نفسها قبل أي OCR.
         *
         * الترتيب ده مقصود. الاستخراج كله شغال على نص الـ OCR، وده
         * معناه إنه مش قادر أصلًا يفرّق بين "بطاقة مش واضحة" و"صورة
         * موتوسيكل". النتيجة اللي كانت بتحصل: العميل يبعت صورة مكنة
         * والبوت يقوله "مش قادر أقرا الرقم القومي، ابعت صورة أوضح
         * للبطاقة" - فيصوّر المكنة تاني. أو يبعت سكرين تطبيق فالبوت
         * يتهمه إنه باعت بطاقة حد تاني.
         *
         * الفحص ده بيقف على حالتين بس وبيرد فيهم برسالة محددة: الصورة
         * نوعها غلط، أو الصورة مش مقروءة. أي حاجة تانية (أو فشل الفحص
         * نفسه) بتكمّل عادي - مش بوابة تقدر توقف طلب سليم.
         */
        $inspection = app(\App\Services\DocumentImageInspector::class)
            ->inspect($disk->path($path), $mime, $docType);

        if (($inspection['message'] ?? null) !== null) {
            \Illuminate\Support\Facades\Log::info('document_image_rejected', [
                'conversation_id' => $conversation->id,
                'expected' => $docType,
                'verdict' => $inspection['verdict'],
                'detected' => $inspection['detected'],
            ]);

            return $this->reply($conversation, $inspection['message']);
        }

        $ocr = app(OcrProviderInterface::class)->recognize($disk->path($path), $mime);

        if ($docType === 'work_app_screens') {
            return $this->handleWorkAppScreenshot(
                $conversation,
                $payload,
                ['path' => $path, 'absolute' => $disk->path($path), 'mime' => $mime],
                (string) ($ocr['text'] ?? '')
            );
        }

        if (! ($ocr['ok'] ?? false)) {
            return $this->reply($conversation, 'مقدرتش أقرا بيانات من الصورة، ممكن تبعتها تاني بجودة أوضح؟');
        }

        $extraction = app(DocumentDataExtractor::class)->extract(
            (string) ($ocr['text'] ?? ''),
            $docType,
            $this->rulesTextFor($docType, $payload['application']['work_vehicle'] ?? null),
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
            ],
            /*
             * الصورة نفسها بتتبعت مع نص الـ OCR: التحقق من الرقم القومي
             * بيرجع للصورة مباشرةً لما الـ OCR ميقراش الرقم صح - وده
             * بيحصل باستمرار على وش البطاقة المصرية (الأرقام صغيرة وورا
             * رسمة الأهرامات).
             */
            ['path' => $disk->path($path), 'mime' => $mime]
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

    /**
     * خطوة سكرينات تطبيق الشغل: بنجمع **بيانات** مش صور.
     *
     * كل سكرين بيتقري وبيتاخد منه اللي فيه، والناقص بس هو اللي بيتطلب
     * تاني. يعني سكرين واحد فيه التلات حاجات بيقفل الخطوة، وسكرين
     * لصفحة مالهاش لازمة مش بيوقف الفلو - بيتقال للعميل الناقص بالظبط.
     */
    private function handleWorkAppScreenshot(
        WhatsappConversation $conversation,
        array $payload,
        array $image,
        string $ocrText
    ): array {
        $application = $payload['application'] ?? [];

        $extraction = app(DocumentDataExtractor::class)->extractWorkAppFacts(
            $ocrText,
            ['path' => $image['absolute'], 'mime' => $image['mime']],
            ['expected_name' => $application['full_name'] ?? null]
        );

        if (! ($extraction['ok'] ?? false)) {
            return $this->reply($conversation, 'مقدرتش أراجع السكرين دلوقتي، ابعته تاني من فضلك.');
        }

        $incoming = $extraction['facts'] ?? [];

        /*
         * الحساب لازم يكون بتاع مقدّم الطلب نفسه. المقارنة بتفهم الاسم
         * بالعربي وبالإنجليزي (تطبيقات التوصيل بتكتبه لاتيني).
         */
        $nameOnScreen = trim((string) ($incoming['account_name'] ?? ''));
        $expectedName = trim((string) ($application['full_name'] ?? ''));

        if ($nameOnScreen !== '' && $expectedName !== ''
            && ! app(DocumentDataExtractor::class)->namesBelongToSamePerson($expectedName, $nameOnScreen)) {
            return $this->reply(
                $conversation,
                "الحساب اللي في السكرين ده باسم \"{$nameOnScreen}\" مش باسم حضرتك ({$expectedName})."
                    . ' لازم السكرينات تكون من حساب مقدّم الطلب نفسه.'
            );
        }

        $facts = $this->mergeWorkAppFacts($payload['work_app_facts'] ?? [], $incoming);
        $paths = $payload['work_app_paths'] ?? [];
        $paths[] = $image['path'];

        $payload['work_app_facts'] = $facts;
        $payload['work_app_paths'] = array_values(array_unique($paths));

        $missing = $this->missingWorkAppFacts($facts);

        if ($missing !== []) {
            $conversation->forceFill([
                'pending_question' => 'application_documents',
                'context_payload' => $payload,
            ])->save();

            $taken = array_values(array_diff(array_keys(self::WORK_APP_FACTS), $missing));

            $lines = [];

            if ($taken !== []) {
                $lines[] = 'تمام يا فندم، خدت من السكرين ده: '
                    . implode('، ', array_map(fn ($key) => self::WORK_APP_FACTS[$key], $taken)) . '.';
            }

            $lines[] = 'لسه محتاج سكرين يبيّن: '
                . implode('، ', array_map(fn ($key) => self::WORK_APP_FACTS[$key], $missing)) . '.';

            if (in_array('income', $missing, true)) {
                $months = count($facts['income_months'] ?? []);

                $lines[] = $months > 0
                    ? "استلمت دخل {$months} شهر لحد دلوقتي، محتاج باقي الشهور عشان تكمل 3 شهور."
                    : 'محتاج صفحة الدخل/الأرباح اللي بتبيّن مبالغ آخر 3 شهور.';
            }

            return $this->reply($conversation, implode("\n", $lines));
        }

        $required = $payload['documents_required'] ?? [];
        $index = (int) ($payload['documents_index'] ?? 0);

        $collected = $payload['documents_collected'] ?? [];
        $collected['work_app_screens'] = [
            'path' => $image['path'],
            'paths' => $payload['work_app_paths'],
            'fields' => $facts,
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
                "تمام يا فندم، استلمت بيانات التطبيق كاملة ✅\n"
                    . "تم رفع طلبك بنجاح! استلمنا كل بياناتك ومستنداتك، وطلبك دلوقتي تحت المراجعة من فريقنا.\n"
                    . 'هنتواصل معاك في أقرب وقت بمجرد ما نخلص المراجعة. شكرًا لثقتك فينا 🙏'
            );
        }

        $conversation->forceFill([
            'pending_question' => 'application_documents',
            'context_payload' => $payload,
        ])->save();

        return $this->reply(
            $conversation,
            "تمام يا فندم، استلمت بيانات التطبيق كاملة ✅\n" . $this->documentPrompt($payload)
        );
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
            'work_app_screens' => [
                'free_income_proof_images' => $doc['paths'] ?? array_filter([$path]),
            ],
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
     * مركبة الشغل اللي اتسجلت خلاص مبتتغيّرش إلا لما العميل نفسه يقولها
     * في الرسالة دي.
     *
     * الباج اللي بتصلحه (محادثة العجلة): العميل قال من الأول "أنا شغال
     * طلبات على عجلة"، واتسجلت bicycle فعلاً والبوت قاله "مفيش رخصة
     * مطلوبة". بعدين بعت اسمه ورقمه القومي وعنوانه - والاستخراج بالـ LLM
     * رجّع work_vehicle تاني من سياق المحادثة (اسم المكنة اللي بيشتريها
     * "دايونج" موتوسيكل)، فالدمج دهس bicycle بـ motorcycle من غير ما
     * العميل يقول أي حاجة. النتيجة: قايمة المستندات اتبنت وفيها رخصة
     * قيادة، والبوت فضل يطلب منه رخصة مستحيل توصل.
     *
     * الاستخراج مصدر شرعي للمركبة **أول مرة** بس. بعد ما تتسجل، التغيير
     * لازم ييجي من نص رسالة العميل نفسه (اللي handle() بيقراه تحت في
     * تصحيح المركبة الصريح)، مش من تخمين الموديل.
     *
     * @param  array<string, mixed>  $application
     * @param  array<string, mixed>  $extracted
     * @return array<string, mixed>
     */
    private function guardStoredWorkVehicle(array $application, array $extracted, string $message): array
    {
        if (! array_key_exists('work_vehicle', $extracted)) {
            return $extracted;
        }

        $stored = $this->normalizeVehicle($application['work_vehicle'] ?? null);

        if ($stored === null) {
            return $extracted;
        }

        $incoming = $this->normalizeVehicle(is_string($extracted['work_vehicle'] ?? null) ? $extracted['work_vehicle'] : null);

        if ($incoming === null || $incoming === $stored) {
            return $extracted;
        }

        /*
         * الاستثناء الوحيد: العميل بيصحح مركبته في الرسالة دي فعلاً -
         * "أنا بقيت شغال على موتوسيكل". ساعتها الاستخراج بيوافق كلامه
         * فبنسيبه يعدي.
         */
        $fromMessage = $this->normalizeVehicle($message);

        $statedNow = $fromMessage === $incoming
            && ($fromMessage === 'bicycle' || $this->messageStatesCurrentWorkVehicle($message));

        if ($statedNow) {
            return $extracted;
        }

        unset($extracted['work_vehicle']);

        return $extracted;
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

    /**
     * هل الرسالة بتتكلم عن المركبة اللي العميل **بيشتغل عليها دلوقتي**،
     * مش عن المكنة اللي بيشتريها؟
     *
     * "أنا شغال طلبات على موتوسيكل"  -> أيوه (مركبة الشغل)
     * "عايز أقسط موتوسيكل"           -> لأ  (المكنة اللي بيشتريها)
     *
     * من غير التمييز ده، أي حد يقول "عايز موتوسيكل" كان هيتسجل إنه
     * شغال على موتوسيكل ونطلب منه رخصة من غير وجه حق.
     */
    private function messageStatesCurrentWorkVehicle(string $message): bool
    {
        $text = $this->normalizeJobText($message);

        /*
         * الكلمات دي بتتقارن بنص **بعد** normalizeJobText، واللي بيحوّل
         * الألف المقصورة لياء - فـ"على" بتبقى "علي". لو سيبنا "على"
         * بالمقصورة هنا، الشرط ده مبيتحققش أبدًا ("أنا شغال على موتوسيكل"
         * ما كانتش بتتسجل مركبة خالص).
         */
        return $this->containsAny($text, [
            'شغال علي', 'شغال ب', 'بشتغل علي', 'بشتغل ب',
            'معايا', 'عندي', 'بستخدم', 'بسوق', 'بركب',
            'شغلي علي', 'شغلي ب', 'بشتغل بيها', 'شغال بيها',
        ]);
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
            'bicycle' => ['work_app_screens'],
            'motorcycle', 'car' => ['work_app_screens', 'driver_license'],
            /*
             * المركبة لسه مش معروفة. الـ default القديم كان بيطلب رخصة
             * قيادة - وده أسوأ افتراض ممكن: العميل اللي على عجلة بيسمع
             * إن مطلوب منه رخصة مش هتتطلب منه أصلًا، فيفهم إنه اترفض
             * ويسيب المحادثة. missingFields() بتوقف الفلو قبل مرحلة
             * المستندات لحد ما work_vehicle يتحدد، فالسطر ده احتياطي -
             * والاحتياطي المفروض يبقى الأقل تطلبًا مش الأكتر.
             */
            default => ['work_app_screens'],
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

        $documents = array_merge($base, $fromMemory ?? $categorySpecific);

        /*
         * العجلة مالهاش رخصة قيادة ولا رخصة مركبة - أيًا كانت الفئة اللي
         * اتصنف عليها الطلب. ده مش تكرار للـ match فوق: الفئة ممكن تكون
         * "تاكسي" (اتصنفت من كلمة في رسالة قديمة) أو تكون جاية من ميموري،
         * والاتنين بيطلبوا رخصة. طلب رخصة من واحد على عجلة معناه إن الطلب
         * بيقف على مستند مستحيل يوصل.
         */
        if ($this->normalizeVehicle($application['work_vehicle'] ?? null) === 'bicycle') {
            $documents = array_values(array_diff($documents, ['driver_license', 'vehicle_license']));
        }

        /*
         * الميموريات اللي اتكتبت في الداشبورد قبل ما خطوة بيانات التطبيق
         * تتعمل بتقول "trips_screenshot" (سكرين واحد عام). الاسم ده بقى
         * اسم قديم لنفس الخطوة، والخطوة الجديدة بتجمع البيانات المطلوبة
         * (تاريخ التعيين + الاسم + دخل 3 شهور) مهما كان عدد السكرينات.
         * من غير التحويل ده، أي ميموري قديمة بتلغي الخطوة الجديدة وترجع
         * تقبل سكرين واحد - وده اللي كان بيحصل فعلاً مع ميموري
         * «ديلفري عجله».
         */
        $documents = array_map(
            fn ($document) => $document === 'trips_screenshot' ? 'work_app_screens' : $document,
            $documents
        );

        return array_values(array_unique($documents));
    }

    /**
     * هل المستند ده سكرين من تطبيق شغل (عام أو صفحة بعينها)؟
     */
    private function isWorkAppScreenshot(?string $docType): bool
    {
        return in_array($docType, ['trips_screenshot', 'work_app_screens'], true);
    }

    /**
     * البيانات المطلوبة من سكرينات تطبيق الشغل، ووصف كل واحدة للعميل.
     *
     * المطلوب بيانات مش عدد صور: لو سكرين واحد فيه التلاتة يبقى خلاص،
     * ولو متفرقين بيتجمعوا. الترتيب ده هو ترتيب الطلب من العميل.
     */
    private const WORK_APP_FACTS = [
        'hiring_date' => 'تاريخ التعيين/الانضمام للتطبيق',
        'account_name' => 'الملف التعريفي اللي فيه اسم حضرتك',
        'income' => 'دخل آخر 3 شهور',
    ];

    /**
     * البيانات اللي لسه ناقصة من سكرينات تطبيق الشغل.
     *
     * @return array<int, string>
     */
    private function missingWorkAppFacts(array $facts): array
    {
        $missing = [];

        if (empty($facts['hiring_date']) && empty($facts['hiring_date_text'])) {
            $missing[] = 'hiring_date';
        }

        if (empty($facts['account_name'])) {
            $missing[] = 'account_name';
        }

        /*
         * الدخل مش بس رقم موجود - لازم يبيّن شغل فعلي (مش حساب فاضي)
         * ويغطي 3 شهور. الشهور بتتجمع من كل السكرينات اللي بعتها.
         */
        if (count($facts['income_months'] ?? []) < 3 || ($facts['account_active'] ?? null) === false) {
            $missing[] = 'income';
        }

        return $missing;
    }

    /**
     * بيدمج بيانات سكرين جديد مع اللي اتجمع قبل كده.
     */
    private function mergeWorkAppFacts(array $facts, array $incoming): array
    {
        foreach (['hiring_date', 'hiring_date_text', 'account_name', 'app_name', 'deliveries_count'] as $key) {
            if (empty($facts[$key]) && ! empty($incoming[$key])) {
                $facts[$key] = $incoming[$key];
            }
        }

        if (($incoming['account_active'] ?? null) === true) {
            $facts['account_active'] = true;
        } elseif (! array_key_exists('account_active', $facts)) {
            $facts['account_active'] = $incoming['account_active'] ?? null;
        }

        $months = $facts['income_months'] ?? [];

        foreach ($incoming['income_periods'] ?? [] as $period) {
            if (! is_array($period) || (float) ($period['amount'] ?? 0) <= 0) {
                continue;
            }

            /*
             * الشهر هو وحدة العد، مش السكرين: سكرين أسبوعي وسكرين يومي من
             * نفس الشهر مايبقوش شهرين. لو الموديل مقدرش يحدد الشهر،
             * بنستعمل نص الفترة زي ما هو عشان على الأقل ما نعدّش نفس
             * الصورة مرتين.
             */
            $key = (string) ($period['month'] ?? $period['label'] ?? '');

            if ($key === '') {
                continue;
            }

            $key = mb_substr($key, 0, 7);

            $months[$key] = (float) ($months[$key] ?? 0) + (float) $period['amount'];
        }

        $facts['income_months'] = $months;

        return $facts;
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
    private function categoryRequirementsNote(string $category, ?string $workVehicle = null): ?string
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

        /*
         * متطلبات الدليفري مش قايمة واحدة - بتتفرع على المركبة. ميموري
         * "الدليفري" العامة أول template_reply فيها بيقول حرفيًا "محتاجين
         * رخصة سارية"، فاللي شغال على عجلة كان بيسمع إن مطلوب منه رخصة
         * مش هتيجي أبدًا (العجلة مالهاش رخصة) ويفهم إنه اترفض. ميموري
         * "ديلفري عجله" هي النسخة الصح ليه: بطاقة + سكرين التطبيق وبس.
         */
        if ($category === 'delivery' && $this->normalizeVehicle($workVehicle) === 'bicycle') {
            $title = 'ديلفري عجله';
        }

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

    private function rulesTextFor(string $docType, ?string $workVehicle = null): string
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
            default => $this->isWorkAppScreenshot($docType) ? ['الدليفري'] : [],
        };

        /*
         * سكرين تطبيق الشغل من واحد شغال على عجلة لازم يتقيّم بقواعد
         * "ديلفري عجله" مش بقواعد "الدليفري" العامة - دي بتتكلم عن رخصة
         * سارية، فالموديل كان ممكن يرفض سكرين سليم أو يطلب رخصة في
         * violation_message لحد شغال على عجلة.
         */
        if ($this->isWorkAppScreenshot($docType) && $this->normalizeVehicle($workVehicle) === 'bicycle') {
            $titles = ['ديلفري عجله'];
        }

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
            'work_app_screens' => "سكرينات من تطبيق الشغل بتبيّن التلات حاجات دول:\n"
                . "• تاريخ التعيين/الانضمام للتطبيق\n"
                . "• الملف التعريفي اللي فيه اسم حضرتك\n"
                . "• دخل آخر 3 شهور\n"
                . 'لو التلاتة ظاهرين في سكرين واحد ابعته لوحده، ولو متفرقين ابعتهم ورا بعض عادي',
            'vehicle_license' => 'رخصة التاكسي/المركبة - لازم تكون سارية وباسم حضرتك',
            // صفحات التطبيقات المحددة - نص الطلب جاي من نفس المصدر اللي
            // بيتحقق منها عشان ما يبقاش فيه وصفين مختلفين لنفس الصفحة.
            default => DocumentDataExtractor::APP_SCREENSHOTS[$docType]['ask'] ?? 'المستند المطلوب',
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
        /*
         * تصحيح بيانات وسط مرحلة المستندات.
         *
         * الباج اللي بتصلحه: العميل كتب رقمه القومي غلط، رفع البطاقة،
         * فالنظام قارن رقم البطاقة بالرقم المكتوب ورفضه ("الرقم القومي
         * الموجود في البطاقة لا يطابق الرقم القومي المسجل في الطلب").
         * بعدها كتب "معلش كتبته غلط، الرقم القومي 3051...". المرحلة دي
         * كانت بتعامل أي نص كأنه مش بيانات - يا سؤال يترد عليه، يا
         * تكرار لطلب المستند - فالتصحيح كان بيتضاع، expected_national_id
         * يفضل الرقم الغلط القديم، وأي رفع جديد للبطاقة يترفض بنفس
         * الرسالة للأبد. حرفيًا loop مالهاش نهاية.
         *
         * القراءة هنا حتمية (أرقام، مش نداء LLM) عشان تصحيح صريح زي ده
         * ما يعتمدش على تخمين.
         */
        $corrected = $this->correctedIdentityFromText($conversation, $payload, $message);

        if ($corrected !== null) {
            return $corrected;
        }

        /*
         * تصحيح المركبة وسط مرحلة المستندات.
         *
         * الباج اللي بتصلحه: البوت طلب "رخصة القيادة" من عميل شغال على
         * عجلة، وهو رد "انا شغال علي عجله مش معايا رخصه" - والمرحلة دي
         * كانت بتتعامل مع أي نص كأنه مش بيانات، فبتعيد طلب الرخصة حرفيًا.
         * العجلة مالهاش رخصة أصلاً، فالطلب كان بيقف على مستند مستحيل
         * يوصل، للأبد.
         */
        $vehicleFix = $this->vehicleCorrectionDuringDocuments($conversation, $payload, $message);

        if ($vehicleFix !== null) {
            return $vehicleFix;
        }

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
                    $aiReply = trim($result['reply']);

                    /*
                     * الرد الحر والـ prompt الثابت مصدرين مختلفين، وكانوا
                     * بيتلزقوا في بعض من غير ما حد يبص هما بيقولوا إيه.
                     * النتيجة كانت رسالة بتناقض نفسها حرفيًا: "ومش محتاجين
                     * رخصة قيادة خالص، ابعتلي الصور دي" وتحتها على طول
                     * "ابعتلي رخصة القيادة من فضلك". ده أسوأ من أي رد
                     * غلط - العميل بيقرا الاتنين ويحس إن مفيش حد فاهم.
                     *
                     * لو الرد نفى مستند إحنا لسه طالبينه، مش بنلزق الطلب
                     * المتناقض. بنسأل عن المركبة بدل كده - دي الإجابة
                     * الوحيدة اللي بتحسم أي المستندين صح.
                     */
                    if ($this->replyContradictsPendingDocument($payload, $aiReply)) {
                        return $aiReply . "\n\n" . 'بس عشان أتأكد وأقفل الموضوع: حضرتك بتشتغل على إيه؟ عجلة ولا موتوسيكل ولا عربية؟';
                    }

                    return $aiReply . "\n\n" . $prompt;
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

    /**
     * يقرا تصحيح رقم قومي / رقم موبايل من رسالة نصية وسط مرحلة المستندات،
     * ويحدّث الطلب فورًا. بيرجّع نص الرد لو حصل تصحيح فعلاً، وnull لو
     * الرسالة مفيهاش تصحيح (فالمرحلة تكمل زي ما هي).
     *
     * ليه الشرط "لازم تختلف عن المحفوظ": العميل بيعيد كتابة نفس الرقم
     * أحيانًا للتأكيد - ده مش تصحيح ومش لازم يعيد دورة كاملة.
     */
    private function correctedIdentityFromText(WhatsappConversation $conversation, array $payload, string $message): ?string
    {
        $application = $payload['application'] ?? [];

        $nationalIdSupport = app(\App\Support\EgyptianNationalId::class);

        /*
         * لازم نحوّل الأرقام العربية (٣٠٥...) للاتيني **من غير** ما نشيل
         * المسافات والحروف اللي بينهم. normalizeDigits بتشيل كل حاجة مش
         * رقم، فرسالة فيها رقمين ("الرقم القومي 305... والموبايل 010...")
         * كانت هتتحول لسلسلة أرقام واحدة ملزوقة، والاستخراج ياخد أول 14
         * خانة من رقمين مختلفين مع بعض - بيانة معطوبة تمامًا. بنطبّع
         * الأرقام في مكانها وبس، وبعدين نقرا كل تتابع أرقام لوحده.
         */
        $normalized = str_replace(
            ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩','۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],
            ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],
            $message
        );

        preg_match_all('/\d+/u', $normalized, $matches);

        $candidates = array_values(array_filter(
            $matches[0] ?? [],
            fn ($run) => in_array(strlen($run), [11, 14], true)
        ));

        if (empty($candidates)) {
            return null;
        }

        $updated = [];

        foreach ($candidates as $candidate) {
            // رقم موبايل مصري: 11 رقم بيبدأ بـ01.
            if (strlen($candidate) === 11 && str_starts_with($candidate, '01')) {
                if (($application['phone'] ?? null) !== $candidate) {
                    $application['phone'] = $candidate;
                    $updated['phone'] = 'رقم الموبايل';
                }

                continue;
            }

            if (strlen($candidate) !== 14) {
                continue;
            }

            $parsed = $nationalIdSupport->parse($candidate);

            /*
             * رقم مش بيفك أصلاً (تاريخ ميلاد مستحيل، قرن غلط) مش تصحيح -
             * سيبه يعدي للمسار العادي بدل ما نكتب بيانة معطوبة فوق
             * بيانة تانية.
             */
            if (! ($parsed['valid'] ?? false)) {
                continue;
            }

            if (($application['national_id'] ?? null) !== $candidate) {
                $application['national_id'] = $candidate;
                $updated['national_id'] = 'الرقم القومي';

                /*
                 * أي رفض متسجّل على الرقم القديم لازم يتشال، وإلا الرقم
                 * الجديد الصح يفضل محمول عليه رفض قديم.
                 */
                unset($application['national_id_issue']);
            }
        }

        if (empty($updated)) {
            return null;
        }

        $payload['application'] = $application;

        /*
         * البطاقة اللي اترفضت اتشالت من المجمّع خلاص، فلازم تترفع تاني
         * عشان تتقارن بالرقم الجديد - documents_index زي ما هو، إحنا بس
         * بنحدّث البيانة اللي المقارنة بتحصل عليها.
         */
        $conversation->forceFill(['context_payload' => $payload])->save();

        $labels = implode(' و', array_values($updated));

        return "تمام يا فندم، عدّلت {$labels} عندي. " . $this->documentPrompt($payload);
    }

    /**
     * حارس ثابت: العجلة مالهاش رخصة قيادة ولا رخصة مركبة - نقطة النهاية
     * دي. بتتنفذ في **أول** كل تعامل مع مرحلة المستندات (قبل أي رسالة
     * أو صورة)، مش بس لما العميل يكتب "أنا على عجلة" صراحةً في نفس
     * الدور - عشان لو القايمة اتسجلت غلط قبل كده (لأي سبب: استخراج AI
     * حط مركبة تانية غلط، أو الفئة اتغيّرت)، العميل يفضل يتطلب منه
     * مستند مستحيل يوصل **كل** دور جاي، مش بس أول مرة تتغير فيها
     * المركبة. يرجّع true لو القايمة اتصلحت (يستاهل حفظ الـ payload).
     */
    private function enforceBicycleHasNoLicence(WhatsappConversation $conversation, array &$payload): bool
    {
        $application = $payload['application'] ?? [];

        if ($this->normalizeVehicle($application['work_vehicle'] ?? null) !== 'bicycle') {
            /*
             * الحقل مش bicycle - بس ده مش معناه إن العميل مقالش. الحقل ده
             * بيضيع (استخراج رجّع مركبة تانية، تعارض، فئة اتغيّرت)، وساعتها
             * كنا بنطلب رخصة من واحد قال بصوت عالي في نفس المحادثة إنه على
             * عجلة. كلام العميل نفسه أقوى من حقل ضاع، فبنرجع نقراه منه.
             */
            if (! $this->bicycleStatedInConversation($conversation)) {
                return false;
            }

            $application['work_vehicle'] = 'bicycle';
            $payload['application'] = $application;
        }

        $required = $payload['documents_required'] ?? [];
        $filtered = array_values(array_diff($required, ['driver_license', 'vehicle_license']));

        if ($filtered === $required) {
            // القايمة سليمة أصلاً؛ بس لو استرجعنا المركبة فوق، التغيير
            // ده لوحده يستاهل يتحفظ عشان مايضيعش تاني.
            return ($payload['application']['work_vehicle'] ?? null) !== ($application['work_vehicle'] ?? null);
        }

        $collected = $payload['documents_collected'] ?? [];
        $nextIndex = count($filtered);

        foreach ($filtered as $position => $document) {
            if (! isset($collected[$document])) {
                $nextIndex = $position;
                break;
            }
        }

        $payload['documents_required'] = $filtered;
        $payload['documents_index'] = $nextIndex;

        return true;
    }

    /**
     * هل الرد الحر بينفي المستند اللي إحنا واقفين مستنينه؟
     *
     * الحالة الوحيدة اللي بتحصل فعلاً: المستند المطلوب رخصة، والرد بيقول
     * إن مفيش رخصة مطلوبة (لإن الموديل شايف من المحادثة إن العميل على
     * عجلة). لزق الطلب بعد كده بيطلّع رسالة بتناقض نفسها في نفس النص.
     *
     * @param  array<string, mixed>  $payload
     */
    private function replyContradictsPendingDocument(array $payload, string $reply): bool
    {
        $required = $payload['documents_required'] ?? [];
        $index = (int) ($payload['documents_index'] ?? 0);
        $pending = $required[$index] ?? null;

        if (! in_array($pending, ['driver_license', 'vehicle_license'], true)) {
            return false;
        }

        $text = $this->normalizeJobText($reply);

        if (! str_contains($text, 'رخصه')) {
            return false;
        }

        return $this->containsAny($text, [
            'مش محتاجين رخصه', 'مش محتاج رخصه', 'مفيش رخصه', 'من غير رخصه',
            'مش هنطلب رخصه', 'مش مطلوب رخصه', 'رخصه مش مطلوبه', 'مش مطلوبه رخصه',
            'مش لازم رخصه', 'بدون رخصه', 'مش محتاجين منك رخصه',
        ]);
    }

    /**
     * هل العميل قال في أي رسالة في المحادثة دي إنه شغال على عجلة؟
     *
     * "عجلة" ملهاش لبس في السياق ده - إحنا مبنبيعش عجل، فأي ذكر ليها في
     * رسالة من العميل بيتكلم عن المركبة اللي بيشتغل عليها. القراءة حتمية
     * (نص، مش نداء LLM) عشان الحارس ده مايعتمدش على تخمين.
     */
    private function bicycleStatedInConversation(WhatsappConversation $conversation): bool
    {
        try {
            $messages = $conversation->messages()
                ->where('direction', 'incoming')
                ->latest('id')
                ->take(40)
                ->pluck('message');
        } catch (\Throwable $e) {
            return false;
        }

        foreach ($messages as $text) {
            if ($this->normalizeVehicle((string) $text) === 'bicycle') {
                return true;
            }
        }

        return false;
    }

    /**
     * بيقرا تصحيح مركبة الشغل من رسالة نصية وسط مرحلة المستندات، وبيعيد
     * بناء قايمة المستندات المطلوبة على أساسها. بيرجّع نص الرد لو حصل
     * تصحيح فعلاً، وnull لو الرسالة مالهاش علاقة (فالمرحلة تكمل زي ما هي).
     */
    private function vehicleCorrectionDuringDocuments(
        WhatsappConversation $conversation,
        array $payload,
        string $message
    ): ?string {
        $required = $payload['documents_required'] ?? [];
        $index = (int) ($payload['documents_index'] ?? 0);
        $pendingDocument = $required[$index] ?? null;

        // بس لما المستند المطلوب دلوقتي بيتغيّر أصلاً بالمركبة.
        if (! in_array($pendingDocument, ['driver_license', 'vehicle_license'], true)
            && ! $this->isWorkAppScreenshot($pendingDocument)) {
            return null;
        }

        $stated = $this->normalizeVehicle($message);

        /*
         * نفس شرط الأمان بتاع handle(): "موتوسيكل/عربية" ممكن يكونوا
         * كلام عن المكنة اللي بيشتريها، فمش بيتقبلوا غير لما الجملة
         * بتقول إنه شغال عليها. "عجلة" مالهاش اللبس ده - إحنا مبنبيعش عجل.
         */
        $accepted = $stated === 'bicycle'
            || ($stated !== null && $this->messageStatesCurrentWorkVehicle($message));

        if (! $accepted) {
            /*
             * "مش معايا رخصة" من غير ما يقول بيشتغل على إيه: إعادة نفس
             * الطلب مالهاش أي معنى - نسأله على المركبة عشان نعرف هل
             * الرخصة مطلوبة منه أصلاً ولا لأ.
             */
            if (! $this->isWorkAppScreenshot($pendingDocument) && $this->messageDeniesLicense($message)) {
                return 'تمام يا فندم، وحضرتك بتشتغل على إيه؟ عجلة ولا موتوسيكل ولا عربية؟';
            }

            return null;
        }

        $application = $payload['application'] ?? [];
        $application['work_vehicle'] = $stated;
        $payload['application'] = $application;

        $documents = $this->requiredDocuments($application);

        $collected = $payload['documents_collected'] ?? [];

        $nextIndex = count($documents);

        foreach ($documents as $position => $document) {
            if (! isset($collected[$document])) {
                $nextIndex = $position;
                break;
            }
        }

        $payload['documents_required'] = $documents;
        $payload['documents_index'] = $nextIndex;
        unset($payload['document_prompt_repeat_streak']);

        $opener = $stated === 'bicycle'
            ? 'تمام يا فندم، ما دام شغال على عجلة يبقى مفيش رخصة مطلوبة منك خالص.'
            : 'تمام يا فندم، سجلت إن حضرتك شغال على ' . ($stated === 'motorcycle' ? 'موتوسيكل' : 'عربية') . '.';

        if ($nextIndex >= count($documents)) {
            $this->createInstallmentRequest($conversation, $payload);

            $conversation->forceFill([
                'pending_question' => null,
                'context_payload' => $payload,
            ])->save();

            return $opener . "\n"
                . "تم رفع طلبك بنجاح يا فندم! ✅\n"
                . 'استلمنا كل بياناتك ومستنداتك، وطلبك دلوقتي تحت المراجعة من فريقنا.';
        }

        $conversation->forceFill([
            'pending_question' => 'application_documents',
            'context_payload' => $payload,
        ])->save();

        return $opener . "\n" . $this->documentPrompt($payload);
    }

    /**
     * "مش معايا رخصة" / "مليش رخصة" - نفي امتلاك الرخصة، مش سؤال ومش
     * مستند. بيتقري حتميًا عشان ما يعتمدش على نداء LLM.
     */
    private function messageDeniesLicense(string $message): bool
    {
        $text = $this->normalizeJobText($message);

        if (! str_contains($text, 'رخصه')) {
            return false;
        }

        return $this->containsAny($text, [
            'مش معايا', 'معنديش', 'مليش', 'مفيش', 'مش معي', 'ملهاش',
            'مستخرجهاش', 'مش مستخرج', 'مش عندي', 'ماعنديش', 'مالييش',
        ]);
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

    /**
     * بيستخرج أي بيانات طلب موجودة في الرسالة ويدمجها في الحالة، من
     * غير ما يغيّر مسار المحادثة.
     *
     * الحاجة ليه: مراحل الطلب كانت بتترتب بالترتيب اللي إحنا عايزينه
     * (اختار المكنة -> كاش ولا تقسيط -> بياناتك)، والعميل الحقيقي مش
     * بيمشي بالترتيب ده - بيبعت اسمه وشغله وعنوانه في رسالة واحدة من
     * أول الكلام. أي معلومة بيقولها لازم تتحفظ أول مرة يقولها، حتى لو
     * إحنا لسه بنسأل عن حاجة تانية. موظف حقيقي كان هيكتبها ورا ودانه،
     * مش هيقوله "دي مش دورها".
     */
    public function bankVolunteeredData(
        WhatsappConversation $conversation,
        array $application,
        string $message,
        ?Machine $machine
    ): array {
        if (trim($message) === '' || mb_strlen(trim($message)) < 3) {
            return $application;
        }

        try {
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
                'selected_machine' => $machine ? [
                    'id' => $machine->id,
                    'name' => $this->machineDisplayName($machine),
                ] : null,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('bankVolunteeredData extraction failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return $application;
        }

        $extracted = $analysis['application_data'] ?? [];

        if (! is_array($extracted) || empty($extracted)) {
            return $application;
        }

        /*
         * في المرحلة دي إحنا لسه مش في حوار البيانات، فمش هنفتح تعارضات
         * ولا نسأل عنها - بنملا الفاضي بس. أي قيمة موجودة خلاص بتفضل زي
         * ما هي، وdetectConflicts هيشتغل عادي بعدين لو العميل غيّر رأيه.
         */
        $extracted = array_filter(
            $extracted,
            fn ($value, $field) => filled($value) && blank($application[$field] ?? null),
            ARRAY_FILTER_USE_BOTH
        );

        /*
         * قاعدة الاستخراج بتقول "رسالة قصيرة = اسم" وهي قاعدة صح جوه
         * حوار البيانات، وغلط هنا: في المرحلة دي الرسالة القصيرة غالبًا
         * محاولة رد على سؤالنا إحنا (اسم مكنة، "تقسيط"، "ايوه"). كلمة
         * واحدة مش اسم عميل، فبنسيبها للمرحلة الصح.
         */
        if (isset($extracted['full_name']) && count(preg_split('/\s+/u', trim($message)) ?: []) < 2) {
            unset($extracted['full_name']);
        }

        if (empty($extracted)) {
            return $application;
        }

        \Illuminate\Support\Facades\Log::info('application_data_banked_early', [
            'conversation_id' => $conversation->id,
            'fields' => array_keys($extracted),
        ]);

        return $this->mergeApplicationData($application, $extracted);
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
