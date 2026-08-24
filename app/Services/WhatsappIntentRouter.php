<?php

namespace App\Services;

use App\Models\InstallmentSystem;
use App\Models\Machine;
use App\Models\WhatsappConversation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\Handlers\ApplicationHandler;
use App\Services\Handlers\MediaOcrHandler;
class WhatsappIntentRouter
{
    /*
     * Scratch space for the current handle() call, populated inside
     * handleInternal() and read back by the handle() wrapper to write a
     * single structured "ai_turn" log entry without threading extra
     * parameters through every branch/return. Reset at the top of every
     * handleInternal() call, so no state leaks between turns.
     */
    private ?string $lastTurnIntent = null;
    private ?float $lastTurnConfidence = null;
    private ?float $lastTurnRepetitionScore = null;

    /**
     * Extra independent requests classified alongside the primary intent
     * (e.g. "سعرها وصورها" -> primary=price, one extra step=images). Only
     * ever populated past the needs_clarification guard in handleInternal(),
     * so a clarification question or handoff naturally leaves this empty.
     */
    private array $lastTurnExtraSteps = [];

    /**
     * Thin wrapper around handleInternal() with two jobs it keeps out of
     * that method's many early-return branches: appending any extra steps
     * the planner found in the same message, and one structured log line
     * per turn answering "what did the AI decide and why" without needing
     * to re-read code (see AI_MEMORY_CONVERSATION_IMPROVEMENT_PLAN.md
     * Section 22).
     */
    public function handle(
        WhatsappConversation $conversation,
        string $message,
        array $mediaItems = []
    ): array {
        $startedAt = microtime(true);
        $statusBefore = $conversation->status ?? 'open';
        $missingFieldsBefore = $conversation->context_payload['missing_fields'] ?? null;
        $clarificationAttemptsBefore = (int) ($conversation->clarification_attempts ?? 0);

        $result = $this->handleInternal($conversation, $message, $mediaItems);

        $conversation->refresh();

        /*
         * Extra steps (independent requests bundled in the same message,
         * e.g. "سعرها وصورها") only get appended to an answer that actually
         * went out cleanly - never to a clarification question or a handoff.
         */
        if (
            ($result['handled'] ?? false) === true
            && ! empty($result['reply'])
            && $conversation->status !== 'awaiting_agent'
        ) {
            $result = $this->appendExtraSteps($conversation, $message, $result);
        }

        Log::info('ai_turn', [
            'conversation_id' => $conversation->id,
            'message_excerpt' => mb_substr($message, 0, 300),
            'intent' => $this->lastTurnIntent,
            'confidence' => $this->lastTurnConfidence,
            'response_type' => $result['type'] ?? null,
            'response_reason' => $result['reason'] ?? null,
            'response_source' => $result['handled'] ?? false ? ($this->lastTurnIntent ? 'deterministic_handler' : 'llm_fallback') : 'unhandled',
            'handled' => $result['handled'] ?? null,
            'missing_fields_before' => $missingFieldsBefore,
            'missing_fields_after' => $conversation->context_payload['missing_fields'] ?? null,
            'clarification_attempts' => $conversation->clarification_attempts ?? 0,
            'clarification_attempts_delta' => (int) ($conversation->clarification_attempts ?? 0) - $clarificationAttemptsBefore,
            'repetition_score' => $this->lastTurnRepetitionScore,
            'escalated' => $statusBefore !== 'awaiting_agent' && $conversation->status === 'awaiting_agent',
            'pending_question' => $conversation->pending_question ?? null,
            'last_topic' => $conversation->last_topic ?? null,
            'extra_steps' => count($this->lastTurnExtraSteps),
            'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        return $result;
    }


/**
 * Executes any extra steps captured for this turn and appends their
 * replies/images onto the primary result. Runs after the primary handler
 * has already saved its own outgoing message and updated conversation
 * state, so:
 * - the primary machine(s) and last_topic take priority: extra-step
 *   machines are added to last_machine_ids (union), never replace it, and
 *   last_topic is restored to whatever the primary handler set;
 * - a failing extra step is logged and skipped, never lets an exception
 *   swallow the primary reply that already succeeded.
 */
private function appendExtraSteps(WhatsappConversation $conversation, string $message, array $result): array
{
    $steps = $this->lastTurnExtraSteps;

    if (empty($steps)) {
        return $result;
    }

    $primaryTopic = $conversation->last_topic;
    $primaryMachineIds = is_array($conversation->last_machine_ids ?? null)
        ? $conversation->last_machine_ids
        : [];

    $extraReplies = [];
    $extraImages = $result['images'] ?? [];
    $extraImageItems = $result['image_items'] ?? [];
    $extraImageGroups = $result['image_groups'] ?? [];

    foreach ($steps as $step) {
        try {
            $stepResult = $this->executeAnswerableStep($conversation, $message, $step);
        } catch (\Throwable $e) {
            Log::warning('extra_step_failed', [
                'conversation_id' => $conversation->id,
                'step' => $step,
                'error' => $e->getMessage(),
            ]);

            continue;
        }

        if (! empty($stepResult['reply'])) {
            $extraReplies[] = trim($stepResult['reply']);
        }

        $extraImages = array_merge($extraImages, $stepResult['images'] ?? []);
        $extraImageItems = array_merge($extraImageItems, $stepResult['image_items'] ?? []);
        $extraImageGroups = array_merge($extraImageGroups, $stepResult['image_groups'] ?? []);

        $conversation->refresh();
    }

    if (empty($extraReplies) && $extraImages === ($result['images'] ?? [])) {
        return $result;
    }

    $mergedMachineIds = array_values(array_unique(array_merge(
        $primaryMachineIds,
        is_array($conversation->last_machine_ids ?? null) ? $conversation->last_machine_ids : []
    )));

    $conversation->forceFill([
        'last_topic' => $primaryTopic,
        'last_machine_ids' => $mergedMachineIds,
    ])->save();

    if (! empty($extraReplies)) {
        $result['reply'] = trim(($result['reply'] ?? '') . "\n\n" . implode("\n\n", $extraReplies));
    }

    $result['images'] = array_values(array_unique(array_filter($extraImages)));
    $result['image_items'] = $extraImageItems;
    $result['image_groups'] = $extraImageGroups;
    $result['image'] = $result['image'] ?? ($result['images'][0] ?? null);

    return $result;
}

/**
 * One extra step is a plan-shaped array (same fields AiIntentClassifier
 * produces for the primary plan) restricted to intents that have a real
 * deterministic handler - no free-form AI fallback and no application
 * flow from a secondary request.
 */
private function executeAnswerableStep(WhatsappConversation $conversation, string $message, array $step): array
{
    $intent = $step['intent'] ?? null;

    $answerable = ['price', 'images', 'installment_calc', 'installment_system', 'brand_models', 'admin_fee_explanation'];

    if (! in_array($intent, $answerable, true)) {
        return ['reply' => null, 'images' => []];
    }

    if ($intent === 'installment_system') {
        return $this->handleInstallmentSystem($conversation, $message);
    }

    $machines = $this->resolveMachinesFromPlan($conversation, $message, $step);

    $brandFiltered = $this->filterMachinesByRequestedBrand($machines, $message);

    if (($brandFiltered['machines'] ?? collect())->isNotEmpty()) {
        $machines = $brandFiltered['machines'];
    }

    if ($machines->count() > 1) {
        $machines = $this->narrowMachinesByVariant($machines, $message);
    }

    if ($machines->isEmpty()) {
        return ['reply' => null, 'images' => []];
    }

    return match ($intent) {
        'price' => $this->handleCashPrice($conversation, $machines, $message),
        'images' => $this->handleImages($conversation, $machines, $message, $step),
        'installment_calc' => $this->handleInstallmentCalc($conversation, $machines, $message, $step),
        'brand_models' => $this->handleBrandModels($conversation, $machines, $message),
        'admin_fee_explanation' => $this->handleAdminFeeExplanation($conversation, $machines, $message),
        default => ['reply' => null, 'images' => []],
    };
}

private function handleInternal(
    WhatsappConversation $conversation,
    string $message,
    array $mediaItems = []
): array {
    $this->lastTurnIntent = null;
    $this->lastTurnConfidence = null;
    $this->lastTurnRepetitionScore = null;
    $this->lastTurnExtraSteps = [];

    try {
        $message = trim($message);

        /*
         * المحادثة دي محوّلة لموظف دعم فعلاً - الـ AI بيسكت تمامًا ومايردش
         * لحد ما الموظف يقفل التحويل من الداشبورد (status يرجع 'open').
         * بنسجل الرسالة الجديدة بس عشان الموظف يشوفها.
         */
        if (($conversation->status ?? 'open') === 'awaiting_agent') {
            $conversation->messages()->create([
                'direction' => 'incoming',
                'message' => $message,
                'payload' => ['media_items' => $mediaItems],
            ]);

            return $this->textResult(null);
        }

        if (count($mediaItems) && $this->allMediaAreVoice($mediaItems)) {
            return $this->handleVoiceMessage($conversation, $message);
        }

        if ($conversation->context_payload && array_key_exists('voice_message_count', $conversation->context_payload)) {
            $cleared = $conversation->context_payload;
            unset($cleared['voice_message_count']);
            $conversation->forceFill(['context_payload' => $cleared])->save();
        }

        if (! count($mediaItems) && $this->isHumanSupportRequest($message)) {
            return $this->handoffToAgent($conversation, $message);
        }

        if (count($mediaItems)) {
            if (($conversation->pending_question ?? null) === 'application_documents') {
                return app(ApplicationHandler::class)->handleDocument($conversation, $mediaItems);
            }

            if ($this->allMediaAreImages($mediaItems)) {
                return app(\App\Services\Handlers\MachineImageRecognitionHandler::class)
                    ->handle($conversation, $mediaItems, $message);
            }

            return app(MediaOcrHandler::class)->handle(
                conversation: $conversation,
                mediaItems: $mediaItems,
                message: $message
            );
        }

        if ($message === '' || $message === '[media]') {
            return $this->textReply($conversation, 'تحت أمرك يا فندم، تحب صور موديل معين ولا سعره؟');
        }

        $plan = app(AiIntentClassifier::class)->classify($conversation, $message);
        $intent = $plan['intent'] ?? 'unknown';

        $this->lastTurnIntent = $intent;
        $this->lastTurnConfidence = isset($plan['confidence']) ? (float) $plan['confidence'] : null;

        $lastMachines = $this->lastMachinesFromConversation($conversation);
        $normalizedMessage = $this->normalizeText($message);

        /*
         * رسالة غضب/شكوى ("نصابين"، "حراميه") غالبًا بتحتوي كلمة زي
         * "سعرها" أو "حسبتها" اللي أصلها مصممة تكشف إن العميل بيسأل عن
         * السعر/بيكمل نفس الموضوع - فكانت بتتحول لإعادة نفس عرض السعر أو
         * نفس حساب القسط بدل ما ترد على الشكوى فعليًا. لو الرسالة شكوى،
         * منسيبش أي heuristic تاني (تضييق/متابعة/تأكيد) تعيد تفسيرها.
         */
        $isComplaint = $this->isComplaintMessage($normalizedMessage);

        $applicationIsPending = in_array(
            $conversation->pending_question ?? null,
            ['application_missing_data', 'application_documents'],
            true
        );

        /*
         * "هي ايه المصاريف الإدارية دي" / "كام %" / "يعني ايه مصاريف
         * إدارية" - سؤال عن المصاريف الإدارية نفسها (شرحها أو قيمتها)،
         * مش طلب شرح نظام التقسيط الكامل من الأول. الكلاسيفاير كان
         * بيصنفها installment_system فيرجع فقرة "التقسيط عندنا متاح لـ..."
         * الكاملة اللي أصلاً معندهاش إجابة السؤال. بنحول الـ intent بس هنا
         * (مش بنرجع فورًا) عشان نكمل نفس مسار تحديد المكنة العادي تحت
         * ونقدر نحسب رقم فعلي لو المكنة معروفة، بدل رد عام دايمًا.
         * "كام %" لوحدها من غير كلمة "مصاريف" بترجع كمتابعة قصيرة لو آخر
         * موضوع في المحادثة كان فعلاً شرح مصاريف إدارية.
         */
        /*
         * "مش عايز ادفع مصاريف اداريه" - رفض صريح، مش سؤال فهم. الرد
         * الصح مش شرح إن المصاريف "لازمة"، ده تحويل لـ installment_calc
         * عادي: InstallmentTextParser::wantsNoAdminFee()/extractSystem()
         * أصلاً بيحولوا النظام لـ 30% تلقائيًا (بدون مصاريف إدارية) لو
         * لقوا نفس الصياغة دي جوه handleInstallmentCalc، وapplyAiParsedInstallment()
         * بيدمج مقدم/مدة اتقالوا في نفس الرسالة من تصنيف الـ AI (اللي بيفهم
         * "20 الف" = 20000 صح، عكس الـ regex الخام). أي حل تاني هنا كان
         * هيكرر نفس المنطق ده بشكل أضعف.
         */
        $mentionsDepositOrMonths = app(InstallmentTextParser::class)->hasDepositMention($normalizedMessage)
            || app(InstallmentTextParser::class)->extractMonths($normalizedMessage) !== null;

        if ($this->isAdminFeeRejectionIntent($normalizedMessage)) {
            $intent = 'installment_calc';
            $plan['intent'] = $intent;
            $plan['needs_clarification'] = false;
            $plan['clarification_question'] = null;
        } elseif (
            $this->isAdminFeeExplanationIntent($normalizedMessage)
            && $mentionsDepositOrMonths
        ) {
            /*
             * "طيب انا هدفع 20 الف مقدم المصاريف الإدارية والإجمالي هيكون
             * كام" - العميل مش بيسأل يفهم، بيطلب حسبة فعلية بمقدم/مدة
             * جديدة. handleAdminFeeExplanation() بيقرا بس من last_deposit
             * المخزن (قديم)، أما installment_calc فبيقرا المقدم/المدة من
             * نفس الرسالة الحالية عن طريق الـ AI classifier مباشرة.
             */
            $intent = 'installment_calc';
            $plan['intent'] = $intent;
            $plan['needs_clarification'] = false;
            $plan['clarification_question'] = null;
        } elseif (
            (
                $this->isAdminFeeExplanationIntent($normalizedMessage)
                /*
                 * Plan task 2.5: this used to fire on the keyword alone and
                 * beat a perfectly good plan - "احسبلي القسط على 12 شهر
                 * بالمصاريف الإدارية" is a calculation request that merely
                 * mentions the fee, and it was being answered with the
                 * generic fee explanation instead. The classifier already
                 * emits admin_fee_explanation itself, so the regex is now
                 * only a safety net for when it did not understand
                 * (general/unknown or a low-confidence guess).
                 */
                && (
                    in_array($intent, ['general', 'unknown', 'admin_fee_explanation'], true)
                    || (float) ($plan['confidence'] ?? 0.0) < 0.5
                )
            )
            || ($conversation->last_topic === 'admin_fee_explanation' && $this->isBareAdminFeeFollowUp($normalizedMessage))
        ) {
            $intent = 'admin_fee_explanation';
            $plan['intent'] = $intent;
            $plan['needs_clarification'] = false;
            $plan['clarification_question'] = null;
        }

        /*
         * تحية صرفة ("مساء الخير") وسط طلب تقديم كانت بتروح على طول
         * لـ ApplicationHandler، وهو مش عنده حاجة يستخرجها منها فيرجع
         * نفس رسالة "ناقصني البيانات دي" تاني - يبان وكأنه متجاهل كلام
         * العميل تمامًا. رد بسيط يعكس التحية ويكمل بعده بملخص الناقص
         * أطبع وألصق بكتير.
         */
        if ($applicationIsPending && $this->isPureGreeting($normalizedMessage)) {
            return $this->withApplicationResume(
                $conversation,
                $this->textReply($conversation, $this->greetingReply($normalizedMessage)),
                true
            );
        }

        /*
         * "عاوز اعمل طلب جديد" / "مكنة تانية غير اللي طلبناه" وسط طلب
         * شغال - العميل بيطلب صراحة يبدأ من موديل تاني، مش يكمل نفس
         * العنوان القديم. كان بيتحط زي أي رسالة تانية جوه applicationIsPending
         * فيرجعله نفس أسئلة العنوان القديمة تاني - loop حقيقي.
         */
        if ($applicationIsPending && $this->isNewApplicationRequest($normalizedMessage)) {
            return $this->restartApplicationForNewMachine($conversation);
        }

        $isBareConfirmationAfterCalc = $conversation->last_topic === 'installment_calc'
            && $this->isBareConfirmation($normalizedMessage);

        /*
         * لو العميل وسط طلب تقديم وسأل سؤال حقيقي بنية واضحة (سعر/صور/نظام
         * تقسيط/توصيل)، منجبروش يكمل التقديم قبل ما نرد عليه - كان ده أهم
         * سبب إن أي سؤال عادي وسط التقديم بيتجاهل تمامًا. لازم يكون
         * التصنيف بثقة معقولة عشان مانقاطعش التقديم على تخمين ضعيف؛
         * التأكيدات البسيطة أو الرسائل الغامضة (general/unknown) لسه
         * بترجع تلقائي لمسار التقديم زي ما كانت.
         */
        $answerableDuringApplication = ['price', 'images', 'installment_system', 'installment_calc', 'delivery_question', 'admin_fee_explanation'];

        /*
         * "تقسيط" / "كاش" مباشرة بعد سؤال "حضرتك عاوز تدفع كاش ولا تقسيط؟"
         * هي *إجابة* السؤال ده، لكن الـ planner بيقراها طلب installment_calc
         * جديد بثقة 1.0، فكانت بتتحسب "سؤال مقاطع" وتروح لحساب القسط -
         * والإجابة تضيع، والتقديم يفضل يسأل نفس السؤال في كل دور بعد كده
         * (loop حقيقي اتشاف في ai:golden-set: missing_fields=[payment_method]
         * على ٦ أدوار متتالية، فـ job_type وبوابة المهن الممنوعة عمرها ما
         * اتنفذت). رد قصير على السؤال المعلّق بيرجع للتقديم دايمًا.
         */
        $awaitingPaymentMethod = $applicationIsPending
            && in_array('payment_method', (array) (($conversation->context_payload['missing_fields'] ?? [])), true);

        $isAnswerToPendingApplicationQuestion = $awaitingPaymentMethod
            && count(preg_split('/\s+/u', trim($normalizedMessage)) ?: []) <= 3
            && preg_match('/(كاش|تقسيط|قسط|نقدي|نقدا)/u', $normalizedMessage) === 1;

        $isConfidentInterruptingQuestion = $applicationIsPending
            && ! $isAnswerToPendingApplicationQuestion
            && in_array($intent, $answerableDuringApplication, true)
            && (float) ($plan['confidence'] ?? 1.0) >= 0.5;

        $resumeApplicationAfterAnswer = $isConfidentInterruptingQuestion;

        if (
            $intent !== 'application_status'
            && ! $isConfidentInterruptingQuestion
            && (
                $applicationIsPending
                || $isBareConfirmationAfterCalc
                || (
                    in_array($intent, ['general', 'unknown'], true)
                    && $this->isApplicationIntent($normalizedMessage, $conversation)
                )
            )
        ) {
            $intent = 'application';
            $plan['intent'] = 'application';
            $plan['target'] = 'single_previous_machine';
            $plan['uses_last_machines'] = true;
            $plan['references_previous'] = true;
            $plan['needs_clarification'] = false;
            $plan['clarification_question'] = null;
        } elseif (

            $lastMachines->count() > 1
            /*
             * لو الـ AI classifier نفسه واثق إن الرسالة بتقصد موديل جديد
             * تمامًا (target=new_machine ومعاه machine_query صريح)، متسيبش
             * كلمة زي "اصلي" جوه اسم الموديل الجديد ("دايو ٦ اصلي") تتفهم
             * غلط كأنها تضييق على آخر موديلات اتعرضت - كان ده بالظبط سبب
             * إن "دايو ٦ اصلي عاوزه على سنة" بيحسب قسط على دايو 2 القديمة
             * بدل دايو ٦ الجديدة تمامًا.
             */
            && ($plan['target'] ?? null) !== 'new_machine'
            && (
                $this->isVariantNarrowingReply($message)
                /*
                 * تضييق عام مش مرتبط بكلمة معينة (استيراد/فرز تاني):
                 * العميل بيكتب اسم/براند قصير بيطابق بعض (مش كل) المكن
                 * اللي عرضناها بالفعل - زي "خليك في VLR" لما نكون رشحنا
                 * أكتر من موديل وواحد منهم بس VLR. الأولوية للتضييق من
                 * نفس المرشحين قبل أي بحث جديد في الكتالوج كله.
                 */
                || $this->isGenericNarrowingReply($lastMachines, $message)
            )
        ) {
            /*
             * لو آخر رد كان فيه أكتر من موديل مطروح (زي "هوجن ٤ استيراد"
             * و"هوجن ٤ استيراد فرز تاني") والعميل رد بكلمة تحديد زي
             * "استيراد" أو "فرز تاني"، منسيبش الـ AI classifier يخمن -
             * بيميل يرجع أول موديل في القايمة القديمة بدل ما يفلتر فعليًا.
             * بنرجع كل الموديلات السابقة وبعدين بنفلترها بالكلمة نفسها.
             */
            $intent = in_array($intent, ['general', 'unknown'], true)
                ? $this->detectIntent($message)
                : $intent;
            $plan['intent'] = $intent;
            $plan['target'] = 'previous_selection';
            $plan['uses_last_machines'] = true;
            $plan['references_previous'] = true;
            $plan['references_all_previous'] = true;
            $plan['needs_clarification'] = false;
            $plan['clarification_question'] = null;
        } elseif (
            in_array($intent, ['general', 'unknown'], true)
            && ! $isComplaint
            && $lastMachines->isNotEmpty()
            && (
                $this->isPureFollowUp($message)
                /*
                 * تأكيد بسيط ("تمام"، "ماشي") بعد ما عرضنا مكنة/مكن، من غير
                 * ما يكون فيه سياق تقسيط شغال (ده متغطي فوق في
                 * isBareConfirmationAfterCalc) - يعني "استمر معايا في
                 * اللي عرضته" مش "ابدأ من الصفر واسألني عاوز مكنه ايه".
                 */
                || $this->isBareConfirmation($normalizedMessage)
            )
        ) {
            $intent = $this->detectIntent($message);
            $plan['intent'] = $intent;
            $plan['target'] = $lastMachines->count() === 1
                ? 'single_previous_machine'
                : 'previous_selection';
            $plan['uses_last_machines'] = true;
            $plan['references_previous'] = true;
            $plan['references_all_previous'] = $lastMachines->count() > 1;
            $plan['needs_clarification'] = false;
            $plan['clarification_question'] = null;
        } elseif (
            in_array($intent, ['general', 'unknown'], true)
            && $this->isInstallmentSystemIntent($normalizedMessage)
        ) {
            $intent = 'installment_system';
            $plan['intent'] = $intent;
        }

        if (($plan['needs_clarification'] ?? false) === true) {
            $question = $plan['clarification_question']
                ?: 'تمام يا فندم، تقصد أنهي موديل بالظبط؟';

            $exhausted = app(\App\Services\ClarificationService::class)->recordAttempt($conversation, $question);

            if ($exhausted) {
                return $this->handoffToAgent($conversation, $message, 'clarification_exhausted');
            }

            return $this->textReply($conversation, $question);
        }

        /*
         * الرسالة اتفهمت بثقة (مش needs_clarification) - أي لخبطة سابقة
         * على موضوع تاني منتهية، نصفر العداد عشان مايتراكمش على مواضيع
         * مش مرتبطة ببعض.
         */
        app(\App\Services\ClarificationService::class)->reset($conversation);

        /*
         * Only reachable once understanding was confident enough to skip
         * clarification, so a clarification question or an escalation never
         * has extra steps attached to it - see handle()'s appendExtraSteps().
         */
        $this->lastTurnExtraSteps = $plan['steps'] ?? [];

        $machines = $this->resolveMachinesFromPlan($conversation, $message, $plan);

        if (in_array($intent, ['general', 'unknown'], true) && ! $isComplaint && $machines->isNotEmpty()) {
            $intent = $this->detectIntent($message);
            $plan['intent'] = $intent;
        }

        $searchMessage = $plan['machine_query'] ?: $message;

        if ($intent === 'installment_calc' && ($plan['target'] ?? null) === 'new_machine') {
            $machines = $this->filterMachinesByRequiredNumbers($machines, $searchMessage);
        }

        $isBrandOnly = app(MachineSearchService::class)->isBrandOnlyRequest($message);

        /*
         * لو الرسالة طلب براند بس (زي "عاوز مكنه دايو")، مبنعتمدش على
         * المكن اللي جت من resolveMachinesFromPlan() (ممكن تبقى فاضلة
         * من محادثة سابقة عن براند تاني تمامًا) - بنعمل بحث جديد مباشر
         * بالبراند المطلوب عشان مايحصلش خلط بين براندات مختلفة.
         */
        if ($isBrandOnly) {
            $freshBrandMachines = app(MachineSearchService::class)->search($message, 20);

            if ($freshBrandMachines->isNotEmpty()) {
                $machines = $freshBrandMachines;
            }
        }

        $brandFiltered = $this->filterMachinesByRequestedBrand($machines, $message);

        if (
            ($brandFiltered['brand_requested'] ?? false)
            && $brandFiltered['machines']->isEmpty()
            && ($brandFiltered['original_machines'] ?? collect())->isNotEmpty()
        ) {
            $matchedMachineDetails = $brandFiltered['original_machines']
                ->map(fn (Machine $machine) => $this->machineDisplayName($machine))
                ->implode(' ، ');

            $availableBrandMachines = Machine::query()
                ->with('brand')
                ->where('brand_id', $brandFiltered['brand_id'])
                ->get();

            $availableBrandList = $availableBrandMachines
                ->map(fn (Machine $machine) => '- ' . $this->machineDisplayName($machine))
                ->implode("\n");

            $reply = $this->renderMemoryOrDefault(
                'رد موديل موجود في براند مختلف',
                [
                    'requested_brand' => $brandFiltered['brand_name'],
                    'matched_machine_details' => $matchedMachineDetails,
                    'available_brand_list' => $availableBrandList,
                ],
                "الموديل ده مش متوفر من {$brandFiltered['brand_name']}."
            );

            return $this->textResult($reply);
        }

        $machines = $brandFiltered['machines'];

        if ($machines->count() > 1) {
            $machines = $this->narrowMachinesByVariant($machines, $message);
        }

        if ($machines->isEmpty() && in_array($intent, ['installment_calc', 'application'], true)) {
            $last = $this->lastMachinesFromConversation($conversation);

            if ($last->isNotEmpty()) {
                $machines = $last;
            }
        }

        if ($intent === 'application_status') {
            return $this->handleApplicationStatus($conversation);
        }

        if ($intent === 'application') {
            /*
             * لو فيه طلب تقديم شغال بالفعل ومقفول على مكنة معينة، مينفعش
             * نسيب أي رسالة "application" (حتى لو مش مقصود بيها تغيير
             * المكنة، زي سؤال حالة أو رسالة مش مفهومة) تعيد تحديد المكنة
             * من last_machine_ids - ده كان بيخلي أي سؤال جانبي عن مكنة
             * تانية "يخطف" الطلب الشغال بالفعل ويحوله عليها. سيب
             * ApplicationHandler يتصرف على المكنة المقفولة زي ما هي.
             */
            $alreadyLockedMachineId = $this->applicationLockedMachineId($conversation);

            if ($alreadyLockedMachineId) {
                return app(ApplicationHandler::class)->handle($conversation, $message);
            }

            if ($machines->isEmpty()) {
                return $this->textReply(
                    $conversation,
                    'تمام يا فندم، تحب تقدم على أنهي موديل؟'
                );
            }

            if ($machines->count() > 1) {
                $list = $machines
                    ->map(fn (Machine $machine) => '- ' . $this->machineDisplayName($machine))
                    ->implode("\n");

                return $this->textReply(
                    $conversation,
                    "تمام يا فندم، تحب تقدم على أنهي موديل؟\n{$list}"
                );
            }

            $this->rememberMachines($conversation, $machines);

            $this->updateConversationState($conversation, 'application', 'application_missing_data', [
                'machine_ids' => $machines->pluck('id')->values()->all(),
                'application' => [
                    'machine_id' => $machines->first()->id,
                    'machine_name' => $this->machineDisplayName($machines->first()),
                ],
            ]);

            return app(ApplicationHandler::class)->handle($conversation, $message);
        }


        if ($intent === 'installment_system') {
            return $this->withApplicationResume(
                $conversation,
                $this->handleInstallmentSystem($conversation, $message),
                $resumeApplicationAfterAnswer
            );
        }

        if ($intent === 'admin_fee_explanation') {
            return $this->withApplicationResume(
                $conversation,
                $this->handleAdminFeeExplanation($conversation, $machines, $message),
                $resumeApplicationAfterAnswer
            );
        }

        if ($intent === 'installment_calc') {
            return $this->withApplicationResume(
                $conversation,
                $this->handleInstallmentCalc($conversation, $machines, $message, $plan),
                $resumeApplicationAfterAnswer
            );
        }

        if ($machines->isNotEmpty() && $isBrandOnly) {
            return $this->handleBrandModels($conversation, $machines, $message);
        }

        if ($intent === 'images') {
            return $this->withApplicationResume(
                $conversation,
                $this->handleImages($conversation, $machines, $message, $plan),
                $resumeApplicationAfterAnswer
            );
        }

        if ($intent === 'price' && $machines->isNotEmpty()) {
            return $this->withApplicationResume(
                $conversation,
                $this->handleCashPrice($conversation, $machines, $message),
                $resumeApplicationAfterAnswer
            );
        }

        /*
         * العميل بيسأل عن سعر بس مقدرناش نطابق موديل بثقة (زي "دايونج"
         * بدل "دايو") - ممنوع نسيب الـ AI العام (handleAiFallback) يرد
         * برقم من عنده، لأنه بيخترع سعر مش موجود فعليًا في الداتابيز.
         * نسأل نتأكد من الاسم الأول بدل ما نديله سعر غلط.
         */
        if ($intent === 'price' && $machines->isEmpty()) {
            return $this->textReply(
                $conversation,
                'تقصد سعر أنهي موديل بالظبط يا فندم؟ أقولك السعر الدقيق أول ما تأكدلي الاسم.'
            );
        }

        return $this->withApplicationResume(
            $conversation,
            $this->handleAiFallback($conversation, $message, $machines),
            $resumeApplicationAfterAnswer
        );
    } catch (\Throwable $e) {
        Log::error('WhatsappIntentRouter simple error', [
            'message' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile(),
        ]);

        if (isset($machines) && $machines instanceof Collection) {
            $this->rememberMachines($conversation, $machines);
        }

        return [
            'handled' => false,
            'type' => 'none',
            'reason' => 'router_exception',
            'error' => $e->getMessage(),
            'reply' => null,
            'image' => null,
            'images' => [],
            'image_items' => [],
            'image_groups' => [],
        ];
    }
}

private function handleAiFallback(
    WhatsappConversation $conversation,
    string $message,
    ?Collection $machines = null
): array {
    /*
     * مهم:
     * ده المسار الوحيد اللي كان بينسى المكنة اللي النظام لقاها بالفعل.
     * لو العميل قال "كي تي اكس" والرد راح للـ AI، لازم نفضل فاكرين
     * إن الكلام على KTX 250، وإلا أول ما يقول بعدها "عاوزها قسط"
     * المحادثة بتبقى فاضية والنظام بيسأله على اسم المكنة تاني.
     * بنحفظها قبل نداء الـ AI عشان الرد نفسه كمان يوصله بيانات
     * المكنة الحقيقية من الداتابيز بدل ما يخمنها من الميموري.
     */
    if ($machines instanceof Collection && $machines->isNotEmpty()) {
        $this->rememberMachines($conversation, $machines);
    }

    $conversation->refresh();

    $recentMessages = $conversation->messages()
        ->latest()
        ->take(15)
        ->get()
        ->reverse()
        ->map(fn ($m) => [
            'direction' => $m->direction,
            'message' => $m->message,
        ])
        ->values()
        ->all();

    $lastMachines = $this->lastMachinesFromConversation($conversation)
        ->map(fn (Machine $machine) => [
            'id' => $machine->id,
            'name' => $this->machineDisplayName($machine),
            'cash_price' => $machine->cash_price,
            'installment_price' => $machine->installment_price,
        ])
        ->values()
        ->all();

    $result = app(AiComplexReplyService::class)->reply($message, [
        'conversation_id' => $conversation->id,
        /*
         * Without this the memory builder always saw intent=null, so its
         * intent filter and every intent-based relevance boost were dead
         * on every single turn (visible in ai_memory_retrieval_logs).
         */
        'intent' => $this->lastTurnIntent,
        'from' => $conversation->from ?? null,
        'is_first_message' => count($recentMessages) <= 1,
        'messages' => $recentMessages,
        'recent_messages' => $recentMessages,
        'last_machines' => $lastMachines,
        'last_machine_ids' => $conversation->last_machine_ids ?? [],
        'current_message' => $message,
    ]);

    if (! ($result['ok'] ?? false)) {
        /*
         * فشل تقني عابر في نداء AI (شبكة/rate-limit/JSON) مش معناه إن الـ
         * AI "معرفش يساعد العميل" - ده الفرق اللي كان ناقص وسبب تحويل
         * أي رسالة عادية زي "مساء الخير" لدعم فني من أول مرة. بنحوّل
         * للدعم بس لو فشل مرتين ورا بعض في نفس المحادثة، مش من أول فشل.
         */
        $failures = (int) data_get($conversation->context_payload, 'ai_fallback_failures', 0) + 1;

        $conversation->forceFill([
            'context_payload' => array_merge(
                $conversation->context_payload ?? [],
                ['ai_fallback_failures' => $failures]
            ),
        ])->save();

        app(GeminiAlertService::class)->transientAiFailureAlert(
            $result['model'] ?? null,
            $result['key_id'] ?? null,
            $result['error'] ?? null
        );

        if ($failures >= 2) {
            return $this->handoffToAgent($conversation, $message, 'technical_failure');
        }

        return $this->textReply(
            $conversation,
            'ثواني يا فندم، هراجعلك التفاصيل وأرد عليك.'
        );
    }

    if ($conversation->context_payload && array_key_exists('ai_fallback_failures', $conversation->context_payload)) {
        $cleared = $conversation->context_payload;
        unset($cleared['ai_fallback_failures']);
        $conversation->forceFill(['context_payload' => $cleared])->save();
    }

    $reply = trim((string) $result['reply']);

    /*
     * الرد الحر ده أضعف نقطة لتكرار نفس الكلام لو الـ AI مش قادر يتقدم
     * في فهم العميل. لو الرد جديد شبه اللي فات جدًا، ده مش "صياغة تانية"
     * لازم نغيرها - ده دليل إن الفهم واقف مكانه، فبنعده كمحاولة توضيح
     * فاشلة (نفس عداد needs_clarification) بدل ما نبعت تكرار تاني.
     */
    $recentOutgoing = collect($recentMessages)
        ->where('direction', 'outgoing')
        ->pluck('message')
        ->filter()
        ->reverse()
        ->take(2)
        ->values()
        ->all();

    $repetitionScore = app(\App\Support\RepetitionGuard::class)->score($reply, $recentOutgoing);
    $this->lastTurnRepetitionScore = $repetitionScore;

    if ($repetitionScore >= 0.75) {
        $exhausted = app(\App\Services\ClarificationService::class)->recordAttempt($conversation, $reply);

        if ($exhausted) {
            return $this->handoffToAgent($conversation, $message, 'repetition_exhausted');
        }
    }

    $this->saveOutgoing($conversation, $reply, [
        'source' => 'ai_fallback',
        'intent' => $result['intent'] ?? null,
        'confidence' => $result['confidence'] ?? null,
        'model' => $result['model'] ?? null,
        'key_id' => $result['key_id'] ?? null,
        'repetition_score' => $repetitionScore,
    ]);

    return $this->textResult($reply);
}


private function detectIntent(string $message): string
{
    $m = $this->normalizeText($message);
if ($this->isInstallmentSystemIntent($m)) {
    return 'installment_system';
}

if ($this->isInstallmentCalcIntent($m)) {
    return 'installment_calc';
}
    /*
     * Intent الصور / الشكل / المعاينة
     */
    if (
        $this->containsAny($m, [
            'صوره',
            'صورة',
            'صور',
            'صورها',
            'صورهم',
            'صورتها',
            'صورتهم',

            'شكل',
            'شكلها',
            'شكلهم',
            'اشوف',
            'اشوفها',
            'اشوفهم',
            'شوف',
            'شوفها',
            'شوفهم',
            'عايز اشوف',
            'عايز اشوفها',
            'عايز اشوفهم',
            'وريني',
            'وريني شكلها',
            'وريني صورها',
            'وريلي',
            'ابعتهالي',
            'ابعتلي شكلها',
            'ابعتلي صور',
            'ابعتلي صورها',
            'هات صور',
            'هات صورها',
            'اعرض',
            'اعرضلي',
            'عرضها',
            'فرجني',
            'فرجني عليها',

            'الوان',
            'ألوان',
            'الوانها',
            'ألوانها',
            'متوفر منها الوان',
            'اللون',
        ])
        || preg_match('/\b(?:show|photo|photos|picture|pictures|image|images|color|colors)\b/i', $m)
    ) {
        return 'images';
    }

    /*
     * Intent السعر / الكاش
     */
    if (
        $this->containsAny($m, [
            'سعر',
            'السعر',
            'سعرها',
            'سعرهم',
            'اسعار',
            'أسعار',
            'اسعارها',
            'أسعارها',
            'اسعارهم',
            'أسعارهم',

            'بكام',
            'كام',
            'كام سعر',
            'كام سعرها',
            'كام سعرهم',
            'عامل كام',
            'عامله كام',
            'عاملين كام',
            'تعمل كام',
            'تمنها',
            'ثمنها',
            'ثمن',
            'تكلفه',
            'تكلفة',
            'تكلف',
            'كلفتها',
            'الكاش',
            'كاش',
            'نقدي',
            'نقدى',
            'فلوسها',
            'فلوس',
            'المبلغ',
            'عرض سعر',
            'اخر سعر',
            'آخر سعر',
            'اقل سعر',
            'أقل سعر',
        ])
        || preg_match('/\b(?:price|cash|cost|how much|offer)\b/i', $m)
    ) {
        return 'price';
    }

    /*
     * default:
     * الرسالة مفيهاش أي كلمة سعر/صور/تقسيط واضحة - ده كان بيترجع 'price'
     * كـ"اختيار آمن"، لكنه في الواقع كان بيخلي أي رسالة مش مفهومة (شكوى،
     * كلام غضب، سؤال عام) تتحول لإعادة عرض السعر أو القسط تلقائيًا، حتى
     * لو معندهاش أي علاقة بالسعر. "general" يخلي الرسالة تروح لمسار الـ
     * AI الحر اللي فعلاً بيقرا سياق المحادثة، بدل تخمين غير آمن.
     */
    return 'general';
}

private function handleCashPrice(WhatsappConversation $conversation, Collection $machines, string $message): array
{
    $lines = [];

    foreach ($machines as $machine) {
        $price = $machine->cash_price
            ? number_format((float) $machine->cash_price) . ' جنيه'
            : 'السعر محتاج تأكيد';

$displayName = $this->machineDisplayName($machine);

$lines[] = [
    'machine' => $machine,
    'price' => $price,
    'line' => "- {$displayName}: {$price}",
];
    }

$machineListPrices = implode("\n", array_column($lines, 'line'));

if ($machines->count() > 1) {
    $reply = $this->renderMemoryOrDefault('رد اسعار عائلة موديل', [
        'machine_list_prices' => $machineListPrices,
        'machine_list' => $machines
            ->map(fn (Machine $machine) => '- ' . $this->machineDisplayName($machine))
            ->implode("\n"),
    ], "الموديلات اللي عندنا من نفس العيلة وأسعارها كاش:\n{$machineListPrices}");
} else {
    $first = $lines[0] ?? null;

    $default = $first
        ? $this->machineDisplayName($first['machine']) . ' سعرها كاش ' . $first['price']
        : 'السعر محتاج تأكيد يا فندم.';

    $reply = $this->renderMemoryOrDefault('رد سعر موديل واحد', [
        'machine_name' => $first ? $this->machineDisplayName($first['machine']) : '',
        'cash_price' => $first['price'] ?? '',
    ], $default);
}

    /*
     * Plan task 2.4: the model words this, it never computes it. $reply is
     * already the correct sentence built from cash_price; AiReplyPhraser
     * only returns a reword that carries the exact same digits and the same
     * "- model: price" lines, otherwise this same text goes out untouched.
     */
    $reply = app(AiReplyPhraser::class)->phrase($reply, [
        'context' => 'سعر كاش',
        'must_keep' => $machines->map(fn (Machine $machine) => $this->machineDisplayName($machine))->all(),
    ]);

    $this->saveOutgoing($conversation, $reply, [
        'source' => 'simple_cash_price',
        'message' => $message,
        'machine_ids' => $machines->pluck('id')->values()->all(),
        'machine_names' => $machines->pluck('name')->values()->all(),
    ]);
    $this->rememberMachines($conversation, $machines);
    $this->updateConversationState($conversation, 'price', null, [
    'machine_ids' => $machines->pluck('id')->values()->all(),
]);
    return array_merge($this->textResult($reply), $this->machineMeta($machines));
}

private function handleImages(
    WhatsappConversation $conversation,
    Collection $machines,
    string $message,
    array $plan = []
): array {
    $aiIntent = $plan;

    if ($machines->isEmpty()) {
        $last = $this->lastMachinesFromConversation($conversation);

        if ($last->isNotEmpty()) {
            $machines = $last;
        }
    }

    if ($machines->isEmpty()) {
        return $this->textReply($conversation, 'تمام يا فندم، تحب صور أنهي موديل؟');
    }

    $allImages = [];
    $imageItems = [];
    $groups = [];

    foreach ($machines as $machine) {
        $displayName = $this->machineDisplayName($machine);
        $images = $this->machineImageUrls($machine);

        $groups[] = [
            'machine_id' => $machine->id,
            'machine_name' => $machine->name,
            'display_name' => $displayName,
            'images' => $images,
        ];

        foreach ($images as $img) {
            $allImages[] = $img;

            $imageItems[] = [
                'url' => $img,
                'caption' => $displayName,
                'machine_id' => $machine->id,
                'machine_name' => $machine->name,
                'display_name' => $displayName,
            ];
        }
    }

    $allImages = array_values(array_unique(array_filter($allImages)));

    if (! count($allImages)) {
        $reply = $this->renderMemoryOrDefault(
            'رد لا توجد صور للموديل',
            [
                'machine_name' => $machines->count() > 1
                    ? $machines->map(fn (Machine $machine) => $this->machineDisplayName($machine))->implode('، ')
                    : $this->machineDisplayName($machines->first()),
            ],
            $machines->count() > 1
                ? 'للأسف مفيش صور متسجلة حاليًا للموديلات دي.'
                : 'للأسف مفيش صور متسجلة حاليًا لـ ' . $this->machineDisplayName($machines->first()) . '.'
        );
    } elseif ($machines->count() > 1) {
        $reply = $this->renderMemoryOrDefault(
            'رد صور عائلة موديل',
            [
                'machine_list' => $machines
                    ->map(fn (Machine $machine) => '- ' . $this->machineDisplayName($machine))
                    ->implode("\n"),
            ],
            'تمام يا فندم، بعتلك الصور وكل صورة مكتوب عليها نوعها.'
        );
    } else {
        $reply = $this->renderMemoryOrDefault(
            'رد صور موديل واحد',
            [
                'machine_name' => $this->machineDisplayName($machines->first()),
            ],
            'اتفضل يا فندم دي صور ' . $this->machineDisplayName($machines->first()) . '.'
        );
    }

    $this->saveOutgoing($conversation, $reply, [
        'source' => 'database_structured_images',
        'message' => $message,
        'ai_intent' => $aiIntent,
        'machine_groups' => $groups,
        'images' => $allImages,
        'image_items' => $imageItems,
    ]);

    $this->rememberMachines($conversation, $machines);

    $this->updateConversationState($conversation, 'images', null, [
        'machine_ids' => $machines->pluck('id')->values()->all(),
    ]);

    return array_merge($this->textResult($reply), $this->machineMeta($machines), [
        'type' => 'images',
        'image' => $allImages[0] ?? null,
        'images' => $allImages,
        'image_items' => $imageItems,
        'image_groups' => $groups,
    ]);
}


    private function machineImageUrls(Machine $machine): array
    {
        $images = [];

        foreach ($this->structuredMachineImages($machine) as $image) {
            $images[] = $image;
        }

        if (count($images)) {
            return array_values(array_unique(array_filter($images)));
        }

        $this->addImage($images, $machine->display_image ?? null);

        foreach (['colors', 'features', 'images'] as $field) {
            if (! Schema::hasColumn('machines', $field) || empty($machine->{$field})) {
                continue;
            }

            $value = $machine->{$field};

            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : $value;
            }

            $this->collectImagesFromValue($images, $value);
        }

        return array_values(array_unique(array_filter($images)));
    }

    private function structuredMachineImages(Machine $machine): array
    {
$folder = $this->safeFolderName($machine->name);  
      $dir = storage_path("app/public/machines-structured/{$folder}");

        if (! File::isDirectory($dir)) {
            return [];
        }

        return collect(File::files($dir))
            ->filter(fn ($file) => preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $file->getFilename()))
            ->sortBy(fn ($file) => str_pad(pathinfo($file->getFilename(), PATHINFO_FILENAME), 10, '0', STR_PAD_LEFT))
            ->map(fn ($file) => url(Storage::url("machines-structured/{$folder}/" . $file->getFilename())))
            ->values()
            ->all();
    }

    private function safeFolderName($name): string
    {
        $name = trim((string) $name);
        $name = preg_replace('/[\/\\\\:*?"<>|]+/u', '-', $name);
        $name = preg_replace('/\s+/u', ' ', $name);

        return $name ?: 'unknown-machine';
    }

    private function collectImagesFromValue(array &$images, $value): void
    {
        if (! $value) {
            return;
        }

        if (is_string($value)) {
            $this->addImage($images, $value);
            return;
        }

        if (is_array($value)) {
            foreach ($value as $child) {
                $this->collectImagesFromValue($images, $child);
            }
        }
    }

    private function addImage(array &$images, $path): void
    {
        if (! $path || ! is_string($path)) {
            return;
        }

        $path = trim($path);

        if (! $this->isValidImagePath($path)) {
            return;
        }

        $images[] = $this->formatMachineImageUrl($path);
    }

    private function isValidImagePath(string $path): bool
    {
        $lower = mb_strtolower(trim($path));

        if (preg_match('/^#?[a-f0-9]{3,8}$/i', $lower) || preg_match('/^\d+$/', $lower)) {
            return false;
        }

        if (Str::startsWith($lower, ['http://', 'https://'])) {
            return preg_match('/\.(jpg|jpeg|png|webp|gif)(\?.*)?$/i', $lower)
                || Str::contains($lower, ['/storage/', '/uploads/', '/images/', '/media/']);
        }

        return preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $lower)
            || Str::contains($lower, ['storage/', 'uploads/', 'images/', 'media/']);
    }

    private function formatMachineImageUrl(string $path): string
    {
        $path = trim($path);

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        $path = str_replace(['/storage/', 'storage/'], '', $path);
        $path = ltrim($path, '/');

        return url(Storage::url($path));
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace('ى', 'ي', $text);
        $text = str_replace('ؤ', 'و', $text);
        $text = str_replace('ئ', 'ي', $text);

        $text = str_replace(
            ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩','۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],
            ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],
            $text
        );

        $text = preg_replace('/\bال(?=[\p{Arabic}]{2,})/u', '', $text);

        $text = str_replace(
            ['استراد', 'وارد', 'فرز ثاني', 'فرز 2', 'فرز تانى', 'تانى', 'اصلى', 'بكام'],
            ['استيراد', 'استيراد', 'فرز تاني', 'فرز تاني', 'فرز تاني', 'تاني', 'اصلي', ' كام'],
            $text
        );

        $text = preg_replace('/[^\p{Arabic}a-zA-Z0-9\s]/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    /**
     * str_word_count() بيعتبر بس أحرف لاتينية "كلمة" افتراضيًا - أي نص
     * عربي صرف بيرجع منه 0 دايمًا، يعني أي شرط زي "str_word_count(...) >
     * 4" كان بيفضل false مهما طالت الرسالة، وأي شرط "<= 3" كان بيفضل true
     * مهما طالت. بديل بسيط بيعتمد على الفصل بمسافات فعليًا.
     */
    private function wordCount(string $text): int
    {
        $text = trim($text);

        if ($text === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $text));
    }

    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            $normalizedNeedle = $this->normalizeText($needle);

            /*
             * normalizeText() بيشيل أي حرف مش عربي/لاتيني/رقم/مسافة، فـ
             * needle زي "%" بيتحول لسلسلة فاضية. str_contains($text, '')
             * بيرجع true دايمًا في PHP، يعني أي needle زي ده كان بيخلي
             * containsAny() ترجع true لأي رسالة مهما كانت.
             */
            if ($normalizedNeedle !== '' && str_contains($text, $normalizedNeedle)) {
                return true;
            }
        }

        return false;
    }

    private function machineMeta(Collection $machines): array
    {
        return [
            'machine_id' => $machines->first()?->id,
            'machine_ids' => $machines->pluck('id')->values()->all(),
            'machine_name' => $machines->first()?->name,
            'machine_names' => $machines->pluck('name')->values()->all(),
        ];
    }

    private function isHumanSupportRequest(string $message): bool
    {
        $m = $this->normalizeText($message);

        return $this->containsAny($m, [
            'دعم فني',
            'الدعم الفني',
            'خدمه العملاء',
            'خدمة العملاء',
            'عايز اكلم حد',
            'عاوز اكلم حد',
            'اكلم موظف',
            'اكلم حد من عندكم',
            'كلمني موظف',
            'حد يرد عليا',
            'حد يتكلم معايا',
            'عايز حد يرد',
            'عاوز حد يرد',
            'شخص حقيقي',
            'انسان حقيقي',
            'مش عايز اتكلم مع بوت',
            'مش عاوز اتكلم مع بوت',
            'مش فاهم حاجه',
            'مش فاهمه حاجه',
        ]);
    }


    /**
     * كلمات غضب/اتهام صريحة - مش "شكوى" بمعنى عام (اللي أي حد يقدر يقصدها
     * بطرق ملهاش نهاية)، لكن مؤشر واضح وموثوق إن العميل مستني اعتذار أو
     * توضيح حقيقي، مش نفس الرد التلقائي اللي زعّله أصلاً.
     */
    private function isComplaintMessage(string $normalizedMessage): bool
    {
        return $this->containsAny($normalizedMessage, [
            'نصاب',
            'نصابين',
            'نصب',
            'حرامي',
            'حراميه',
            'حرامية',
            'سرقه',
            'سرقة',
            'بتسرقوا',
            'استغلال',
            'مستغلين',
            'غشاشين',
            'غش',
            'مش معقول',
            'مسخره',
            'مسخرة',
            'بتتلاعبوا',
            'تلعبوا فينا',
            'حرام عليكم',
        ]);
    }


    /**
     * العميل بيسأل يفهم إيه هي المصاريف الإدارية بالظبط ("هي ايه"، "يعني
     * ايه"، "ليه فيه"...)، مش بيسأل عن نظام التقسيط عمومًا. normalizeText()
     * بيشيل "ال" التعريف، فـ"المصاريف الإدارية" بتتحول "مصاريف اداريه".
     */
    private function isAdminFeeExplanationIntent(string $normalizedMessage): bool
    {
        if (! $this->containsAny($normalizedMessage, ['مصاريف اداريه', 'مصاريف ادارية'])) {
            return false;
        }

        return $this->containsAny($normalizedMessage, [
            'هي ايه', 'هي اي', 'يعني ايه', 'يعني اي', 'ايه هي',
            'ليه', 'إيه', 'ايه دي', 'دي ايه', 'مش فاهم', 'مش فاهمه',
            'فيها ايه', 'بتتحسب ازاي', 'اد ايه',
            'كام', 'قد ايه', 'نسبتها', 'نسبته', 'قيمتها', 'قيمته',
            '%', 'في الميه', 'بالميه', 'في المئه',
        ]);
    }

    /**
     * متابعة قصيرة زي "كام %" أو "قد ايه" لوحدها من غير كلمة "مصاريف" -
     * بترجع كـadmin_fee_explanation بس لو آخر موضوع في المحادثة فعلاً كان
     * شرح المصاريف الإدارية، عشان مانخطفش أي رسالة قصيرة تانية زي "كام
     * شهر" أو "قد ايه القسط".
     */
    private function isBareAdminFeeFollowUp(string $normalizedMessage): bool
    {
        $normalized = trim($normalizedMessage);

        if ($normalized === '' || $this->wordCount($normalized) > 4) {
            return false;
        }

        return $this->containsAny($normalized, [
            'كام', 'قد ايه', 'نسبتها', 'نسبته', '%', 'في الميه', 'بالميه', 'في المئه',
        ]);
    }

    /**
     * "مش عايز ادفع مصاريف اداريه" / "ينفع من غير مصاريف اداريه" - رفض
     * صريح، مش سؤال فهم. الرد الصح هنا إحالة لنظام 30% مش شرح إن المصاريف
     * "لازمة".
     */
    private function isAdminFeeRejectionIntent(string $normalizedMessage): bool
    {
        if (! $this->containsAny($normalizedMessage, ['مصاريف اداريه', 'مصاريف ادارية'])) {
            return false;
        }

        return $this->containsAny($normalizedMessage, [
            'مش عايز', 'مش عاوز', 'مش هدفع', 'مش حادفع', 'مش هادفع',
            'من غير', 'بدون', 'الغي', 'إلغاء', 'الغاء', 'ملهاش',
            'من غيرها', 'من غير كده', 'ينفع من غير', 'ينفع بدون',
        ]);
    }

    /**
     * "هي ايه مصاريف اداريه" / "كام %" - العميل بيسأل عن المصاريف
     * الإدارية نفسها. لو المكنة معروفة من السياق، برد رقم فعلي (7% من
     * سعرها في التقسيط) مش شرح عام بس. لو كمان معروف حساب قسط سابق لنفس
     * المكنة في المحادثة دي، بضيف إجمالي أول قسط (القسط الشهري + المصاريف
     * الإدارية + المقدم لو فيه).
     */
    private function handleAdminFeeExplanation(
        WhatsappConversation $conversation,
        Collection $machines,
        string $message
    ): array {
        if ($machines->isEmpty()) {
            $last = $this->lastMachinesFromConversation($conversation);

            if ($last->isNotEmpty()) {
                $machines = $last;
            }
        }

        if ($machines->isEmpty()) {
            $reply = $this->renderMemoryOrDefault(
                'رد شرح المصاريف الإدارية',
                [],
                'المصاريف الإدارية بتكون 7% من تمن المكنة، وبتتدفع وانت بتستلم المكنة (مش أول قسط)، ودي رسوم شركة التمويل بتحطها مش من المعرض. وأول قسط شهري بيكون بعد الاستلام بـ 45 يوم.'
            );

            $this->saveOutgoing($conversation, $reply, ['source' => 'admin_fee_explanation_generic']);
            $this->updateConversationState($conversation, 'admin_fee_explanation');

            return $this->textResult($reply);
        }

        $machine = $machines->first();
        $installmentPrice = (float) ($machine->installment_price ?? 0);
        $displayName = $this->machineDisplayName($machine);

        if ($installmentPrice <= 0) {
            $reply = 'المصاريف الإدارية بتكون 7% من تمن المكنة في التقسيط، بتتدفع وانت بتستلم المكنة (مش أول قسط)، ودي رسوم شركة التمويل مش من المعرض. سعر التقسيط لسه محتاج تأكيد لـ ' . $displayName . ' عشان أقولك الرقم بالظبط.';

            $this->saveOutgoing($conversation, $reply, ['source' => 'admin_fee_explanation_no_price']);
            $this->rememberMachines($conversation, $machines);
            $this->updateConversationState($conversation, 'admin_fee_explanation');

            return $this->textResult($reply);
        }

        $adminFee = (int) round($installmentPrice * 0.07);

        $lines = [
            "المصاريف الإدارية لـ {$displayName} بتكون " . number_format($adminFee)
                . ' جنيه (7% من تمنها في التقسيط)، بتتدفع وانت بتستلم المكنة، ودي رسوم شركة التمويل بتحطها مش من المعرض.',
        ];

        $payload = $conversation->context_payload ?? [];
        $lastCalcMachineIds = collect($payload['last_calc_machine_ids'] ?? [])->all();
        $hasMatchingCalc = in_array($machine->id, $lastCalcMachineIds, true)
            && array_key_exists('last_months', $payload);

        if ($hasMatchingCalc) {
            $months = (int) $payload['last_months'];
            $deposit = (float) ($payload['last_deposit'] ?? 0);
            $system = (string) ($payload['last_system'] ?? '20');

            $calc = app(InstallmentCalculator::class)->calculate($machine, $months, $deposit, $system);

            if ($calc['ok'] ?? false) {
                $monthly = (int) $calc['monthly_payment'];
                $calcAdminFee = (int) $calc['admin_fee'];
                $freelanceExtra = (float) ($calc['freelance_extra_deposit'] ?? 0);
                $totalAtPickup = $calcAdminFee + $deposit + $freelanceExtra;

                $depositLine = $deposit > 0 ? ' + المقدم ' . number_format($deposit) . ' جنيه' : '';
                $freelanceLine = $freelanceExtra > 0 ? ' + مقدم إضافي (سقف الدخل الحر) ' . number_format($freelanceExtra) . ' جنيه' : '';

                $lines[] = 'وقت استلام المكنة هتدفع: المصاريف الإدارية ' . number_format($calcAdminFee) . ' جنيه'
                    . $depositLine . $freelanceLine
                    . ' = إجمالي ' . number_format($totalAtPickup) . ' جنيه.'
                    . "\nوبعد كده بـ 45 يوم من الاستلام، أول قسط شهري يكون " . number_format($monthly) . ' جنيه.';
            }
        }

        $reply = implode("\n\n", $lines);

        $this->saveOutgoing($conversation, $reply, [
            'source' => 'admin_fee_explanation',
            'machine_id' => $machine->id,
        ]);

        $this->rememberMachines($conversation, $machines);
        $this->updateConversationState($conversation, 'admin_fee_explanation', null, [
            'machine_ids' => $machines->pluck('id')->values()->all(),
        ]);

        return array_merge($this->textResult($reply), $this->machineMeta($machines));
    }

    /**
     * A short message that's basically just a greeting, not carrying any
     * real content - "مساء الخير" wouldn't produce anything extractable
     * for ApplicationHandler, so treating it as an application-flow
     * message just re-sent the same missing-fields template with no
     * acknowledgment of what the customer actually said. Capped at 4
     * words so a greeting genuinely mixed with real data ("مساء الخير
     * اسمي أحمد") still goes through normal extraction instead of being
     * swallowed here.
     */
    private function isPureGreeting(string $normalizedMessage): bool
    {
        $normalized = trim($normalizedMessage);

        if ($normalized === '' || $this->wordCount($normalized) > 4) {
            return false;
        }

        return $this->containsAny($normalized, [
            'صباح الخير',
            'مساء الخير',
            'السلام عليكم',
            'سلام عليكم',
            'اهلا',
            'أهلا',
            'ازيك',
            'ازيكم',
            'إزيك',
            'إزيكم',
            'هاي',
            'هلا',
            'صباح النور',
            'مساء النور',
        ]);
    }

    private function greetingReply(string $normalizedMessage): string
    {
        if (str_contains($normalizedMessage, 'صباح')) {
            return 'صباح النور يا فندم!';
        }

        if (str_contains($normalizedMessage, 'مساء')) {
            return 'مساء النور يا فندم!';
        }

        if (str_contains($normalizedMessage, 'سلام عليكم')) {
            return 'وعليكم السلام يا فندم!';
        }

        return 'أهلاً بيك يا فندم!';
    }


    /**
     * Explicit "I want a different machine / a new application" while
     * still mid-application - the customer is asking to abandon the
     * machine currently in progress, not to be re-asked the same address
     * questions forever. Kept deliberately narrow (explicit switch
     * language only) so an ordinary missing-field answer never matches.
     */
    private function isNewApplicationRequest(string $normalizedMessage): bool
    {
        return $this->containsAny($normalizedMessage, [
            'طلب جديد',
            'طلب تاني',
            'طلب تانى',
            'مكنه تانيه',
            'مكنة تانية',
            'موديل تاني',
            'موديل تانى',
            'غير اللي طلبناه',
            'غير الي طلبناه',
            'الغاء الطلب',
            'إلغاء الطلب',
            'ابدا من الأول',
            'ابدأ من الأول',
            'ابدا من الاول',
        ]);
    }

    /**
     * Resets the machine-specific part of the in-progress application
     * (machine choice, payment method, document collection state) and
     * asks which model the customer wants instead - but keeps everything
     * already known about the customer themselves (name, ID, phone, job,
     * income proof, addresses), since none of that changes just because
     * they picked a different machine.
     */
    private function restartApplicationForNewMachine(WhatsappConversation $conversation): array
    {
        $payload = $conversation->context_payload ?? [];
        $application = $payload['application'] ?? [];

        foreach (['machine_id', 'machine_name', 'payment_method'] as $key) {
            unset($application[$key]);
        }

        $payload['application'] = $application;
        $payload['missing_fields'] = [];
        unset($payload['pending_conflicts'], $payload['no_progress_streak'], $payload['documents_required'], $payload['documents_index'], $payload['documents_collected']);

        $conversation->forceFill([
            'last_machine_id' => null,
            'last_machine_ids' => null,
            'last_topic' => null,
            'pending_question' => null,
            'context_payload' => $payload,
        ])->save();

        $reply = 'تمام يا فندم، تحب تقدم على أنهي موديل؟';

        $this->saveOutgoing($conversation, $reply, ['source' => 'application_restart_new_machine']);

        return $this->textResult($reply);
    }


    /**
     * The machine id already locked into an in-progress application, if
     * any - null when there's no application running, or none has a
     * machine chosen yet.
     */
    private function applicationLockedMachineId(WhatsappConversation $conversation): ?int
    {
        if (! in_array($conversation->pending_question ?? null, ['application_missing_data', 'application_documents'], true)) {
            return null;
        }

        $payload = $conversation->context_payload ?? [];
        $machineId = $payload['application']['machine_id'] ?? null;

        return $machineId ? (int) $machineId : null;
    }

    /**
     * كل عناصر الوسائط المرفقة صوتية (رسالة صوتية/voice note)، مفيش أي
     * صورة أو مستند مع الصوت.
     */
    private function allMediaAreVoice(array $mediaItems): bool
    {
        if (! count($mediaItems)) {
            return false;
        }

        foreach ($mediaItems as $item) {
            $type = strtolower((string) ($item['type'] ?? $item['media_type'] ?? ''));
            $mime = strtolower((string) ($item['mime'] ?? $item['media_mime'] ?? ''));

            if ($type !== 'audio' && ! str_starts_with($mime, 'audio/')) {
                return false;
            }
        }

        return true;
    }

    /**
     * كل عناصر الوسائط المرفقة صور (مش مستندات/PDF)، عشان نجرب نتعرف
     * على المكنة في الصورة قبل ما نحول للـ OCR بتاع المستندات.
     */
    private function allMediaAreImages(array $mediaItems): bool
    {
        if (! count($mediaItems)) {
            return false;
        }

        foreach ($mediaItems as $item) {
            $type = strtolower((string) ($item['type'] ?? $item['media_type'] ?? ''));
            $mime = strtolower((string) ($item['mime'] ?? $item['media_mime'] ?? ''));

            $isImage = $type === 'image' || str_starts_with($mime, 'image/');

            if (! $isImage || str_contains($mime, 'pdf')) {
                return false;
            }
        }

        return true;
    }

    /**
     * حاليًا الـ AI مقدرش يحلل رسائل صوتية. أول مرتين بنطلب من العميل
     * يكتب طلبه، وأي محاولة تالتة بنحوّله لدعم فني بدل ما نكرر نفس الرد
     * لما نهاية ملهاش.
     */
    private function handleVoiceMessage(WhatsappConversation $conversation, string $message): array
    {
        $count = (int) data_get($conversation->context_payload, 'voice_message_count', 0) + 1;

        $conversation->forceFill([
            'context_payload' => array_merge(
                $conversation->context_payload ?? [],
                ['voice_message_count' => $count]
            ),
        ])->save();

        if ($count >= 3) {
            return $this->handoffToAgent($conversation, $message ?: '[رسالة صوتية]', 'repeated_voice_messages');
        }

        $reply = $this->renderMemoryOrDefault(
            'رد رسائل صوتية غير مدعومة',
            [],
            'معلش يا فندم، مقدرش أسمع الرسائل الصوتية دلوقتي، ممكن تكتبلي طلبك بالكتابة؟'
        );

        return $this->textReply($conversation, $reply);
    }

    /**
     * بيحول المحادثة لموظف دعم: status = awaiting_agent يوقف رد الـ AI
     * تمامًا (شيك أول handle())، وتظهر في tab "الرسائل المنتظر الرد
     * عليها" بالداشبورد لحد ما الموظف يرد ويقفل التحويل.
     */
    private function handoffToAgent(WhatsappConversation $conversation, string $message, string $reason = 'explicit_request'): array
    {
        $conversation->forceFill(['status' => 'awaiting_agent'])->save();

        $reply = $this->renderMemory('رد تحويل لدعم فني')
            ?: 'تمام يا فندم، هحولك دلوقتي لموظف خدمة عملاء هيتواصل معاك في أقرب وقت.';

        $this->saveOutgoing($conversation, $reply, [
            'source' => 'human_handoff',
            'message' => $message,
        ]);

        Log::info('ai_escalation', [
            'conversation_id' => $conversation->id,
            'reason' => $reason,
            'message' => $message,
            'clarification_attempts' => $conversation->clarification_attempts ?? 0,
            'last_clarification_question' => $conversation->last_clarification_question ?? null,
        ]);

        $this->archiveChatOnWhatsapp($conversation);

        return $this->textResult($reply);
    }

    /**
     * بيحط المحادثة في أرشيف الواتساب نفسه (مش داشبورد بس) عشان أي حد
     * فاتح الواتساب مباشرة يلاقيها في الأرشيف. لو فشل، مبيوقفش التحويل -
     * الأهم إن الـ AI يسكت وconversation تتعلم في الداشبورد على أي حال.
     */
    private function archiveChatOnWhatsapp(WhatsappConversation $conversation): void
    {
        $lastIncoming = $conversation->messages()
            ->where('direction', 'incoming')
            ->latest('id')
            ->first();

        $botId = data_get($lastIncoming?->payload, 'bot_id') ?: $conversation->whatsapp_bot_id;
        $jid = data_get($lastIncoming?->payload, 'reply_jid')
            ?: data_get($lastIncoming?->payload, 'from')
            ?: ($conversation->phone ? $conversation->phone . '@s.whatsapp.net' : null);

        if (! $botId || ! $jid) {
            return;
        }

        try {
            \Illuminate\Support\Facades\Http::timeout(10)
                ->withHeaders([
                    'X-BOT-TOKEN' => config('services.whatsapp.bot_token'),
                    'Accept' => 'application/json',
                ])
                ->post(config('services.whatsapp.worker_url') . '/chats/archive', [
                    'bot_id' => (string) $botId,
                    'jid' => (string) $jid,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to archive WhatsApp chat on handoff', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function textReply(WhatsappConversation $conversation, string $reply): array
    {
        $this->saveOutgoing($conversation, $reply, [
            'source' => 'simple_router_text',
        ]);

        return $this->textResult($reply);
    }


    /**
     * Appends a short "still need X" resume line to an answer given while
     * an application was interrupted for a genuine question (see the
     * interrupt/resume decision in handle()). pending_question is left
     * untouched by the caller, so the next message still resumes the
     * application normally - this only makes the current reply mention
     * what's still missing instead of silently dropping the topic.
     */
    private function withApplicationResume(WhatsappConversation $conversation, array $result, bool $shouldResume): array
    {
        if (! $shouldResume || empty($result['reply'])) {
            return $result;
        }

        $payload = $conversation->context_payload ?? [];
        $application = $payload['application'] ?? [];

        if (empty($application)) {
            return $result;
        }

        try {
            $stateService = app(\App\Services\ApplicationStateService::class);
            $application = $stateService->refreshAddressComponents($application);

            $isFreelance = app(ApplicationHandler::class)->categorizeIncome(
                (string) ($application['job_type'] ?? ''),
                (string) ($application['income_proof'] ?? '')
            ) === 'freelance';

            $missing = $stateService->missingFields($application, $isFreelance);

            if (empty($missing)) {
                return $result;
            }

            $labels = [
                'full_name' => 'الاسم بالكامل',
                'national_id' => 'الرقم القومي',
                'phone' => 'رقم الموبايل',
                'job_type' => 'طبيعة شغلك',
                'income_proof' => 'إثبات الدخل',
                'work_address' => 'عنوان الشغل',
                'home_address' => 'عنوان السكن',
                'installment_months' => 'مدة التقسيط',
            ];

            $missingLabels = implode(' و', array_map(fn ($key) => $labels[$key] ?? $key, $missing));

            $result['reply'] = trim($result['reply']) . "\n\nولسه ناقصني {$missingLabels} عشان أكمل طلب التقديم.";
        } catch (\Throwable $e) {
            // Never let the resume-prompt convenience break a reply that
            // already succeeded.
        }

        return $result;
    }

    private function textResult(?string $reply, array $extra = []): array
    {
        return array_merge([
            'handled' => true,
            'type' => 'text',
            'reply' => $reply,
            'image' => null,
            'images' => [],
            'image_items' => [],
            'image_groups' => [],
        ], $extra);
    }

    private function saveOutgoing(WhatsappConversation $conversation, string $reply, array $payload = []): void
    {
        $conversation->messages()->create([
            'direction' => 'outgoing',
            'message' => $reply,
            'payload' => $payload,
        ]);
    }
    
private function machineDisplayName(Machine $machine): string
{
    $brand = $this->machineBrandName($machine);
    $name = trim((string) $machine->name);

    if ($brand !== '' && ! str_contains(mb_strtolower($name), mb_strtolower($brand))) {
        return $brand . ' ' . $name;
    }

    return $name;
}

private function rememberMachines(WhatsappConversation $conversation, Collection $machines): void
{
    if ($machines->isEmpty()) {
        return;
    }

    $ids = $machines
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->filter(fn (int $id) => $id > 0)
        ->unique()
        ->values()
        ->all();

    if (empty($ids)) {
        return;
    }

    $currentActiveId = (int) ($conversation->last_machine_id ?? 0);
    $activeId = count($ids) === 1
        ? $ids[0]
        : (in_array($currentActiveId, $ids, true) ? $currentActiveId : null);

    $data = [
        'last_machine_id' => $activeId,
        'last_machine_ids' => $ids,
    ];

    $allowed = [];

    foreach ($data as $key => $value) {
        if (Schema::hasColumn('whatsapp_conversations', $key)) {
            $allowed[$key] = $value;
        }
    }

    if ($allowed) {
        $conversation->forceFill($allowed)->save();
    }
}

private function lastMachinesFromConversation(WhatsappConversation $conversation): Collection
{
    $ids = Schema::hasColumn('whatsapp_conversations', 'last_machine_ids')
        ? ($conversation->last_machine_ids ?? [])
        : [];

    if (is_string($ids)) {
        $ids = json_decode($ids, true) ?: [];
    }

    if (! is_array($ids)) {
        $ids = [];
    }

    if (
        empty($ids)
        && Schema::hasColumn('whatsapp_conversations', 'last_machine_id')
        && ! empty($conversation->last_machine_id)
    ) {
        $ids = [(int) $conversation->last_machine_id];
    }

    $ids = array_values(array_unique(array_filter(
        array_map('intval', $ids),
        fn (int $id) => $id > 0
    )));

    if (empty($ids)) {
        return collect();
    }

    return Machine::query()
        ->with('brand')
        ->whereIn('id', $ids)
        ->get()
        ->sortBy(fn ($machine) => array_search($machine->id, $ids, true))
        ->values();
}

private function activeMachineFromConversation(WhatsappConversation $conversation): ?Machine
{
    if (
        Schema::hasColumn('whatsapp_conversations', 'last_machine_id')
        && ! empty($conversation->last_machine_id)
    ) {
        $machine = Machine::query()
            ->with('brand')
            ->find((int) $conversation->last_machine_id);

        if ($machine) {
            return $machine;
        }
    }

    return $this->lastMachinesFromConversation($conversation)->first();
}

private function resolveMachinesFromPlan(
    WhatsappConversation $conversation,
    string $message,
    array $plan
): Collection {
    $target = $plan['target'] ?? 'unknown';
    $hasMachineQuery = ! empty($plan['machine_query']);

    /*
     * لو الـ AI قال "target = يرجع لموديل سابق" بس فعليًا مفيش موديل
     * سابق في المحادثة (أول رسالة، أو محادثة جديدة)، ومعانا اسم موديل
     * صريح في machine_query، منسيبش الرد يقف فاضي - نكمل على البحث
     * بالاسم بدل ما نرجع collection فاضية.
     */
    if ($target === 'previous_selection') {
        $last = $this->lastMachinesFromConversation($conversation);

        if ($last->isNotEmpty() || ! $hasMachineQuery) {
            return $last;
        }
    }

    if ($target === 'single_previous_machine') {
        $machine = $this->activeMachineFromConversation($conversation);

        if ($machine) {
            return collect([$machine]);
        }

        if (! $hasMachineQuery) {
            return collect();
        }
    }

    if ($target === 'selected_index') {
        $index = (int) ($plan['selected_index'] ?? 0);

        if ($index > 0) {
            $last = $this->lastMachinesFromConversation($conversation);
            $machine = $last->get($index - 1);

            if ($machine) {
                return collect([$machine]);
            }

            if (! $hasMachineQuery) {
                return collect();
            }
        }
    }

    if ($hasMachineQuery) {
        $query = trim((string) $plan['machine_query']);

        $found = app(MachineSearchService::class)->search($query, 20);

        if ($found->isEmpty()) {
            $found = app(MachineSearchService::class)->search($message, 20);
        }

        if ($found->isNotEmpty()) {
            return $found;
        }
    }

    if (! empty($plan['machine_ids']) && is_array($plan['machine_ids'])) {
        return Machine::query()
            ->with('brand')
            ->whereIn('id', $plan['machine_ids'])
            ->get()
            ->sortBy(fn ($machine) => array_search($machine->id, $plan['machine_ids'], true))
            ->values();
    }

    if (($plan['uses_last_machines'] ?? false) === true) {
        return $this->lastMachinesFromConversation($conversation);
    }
    
    if (($plan['intent'] ?? null) === 'installment_calc') {
    $last = $this->lastMachinesFromConversation($conversation);

    if ($last->isNotEmpty()) {
        return $last;
    }

    return collect();
}

    return app(MachineSearchService::class)->search($message, 20);
}


/**
 * لو الرسالة قصيرة (1-3 كلمات، مفيهاش أرقام كتير) وبتطابق جزء (مش كل)
 * المكن اللي اتعرضت قبل كده، اعتبرها تضييق مش طلب جديد. بنستخدم نفس
 * normalizeSearchText بتاعة MachineSearchService عشان نتجنب تكرار أي
 * أخطاء تطبيع (زي مشكلة دايو/دايون اللي اتصلحت هناك)، وتطابق متسامح مع
 * غلطات إملائية (حرف ناقص/زايد) عشان رد زي "فز تاني" يتطابق مع "فرز
 * تاني" من غير ما نحتاج نضيف كل غلطة ممكنة يدويًا.
 */
private function isGenericNarrowingReply(Collection $lastMachines, string $message): bool
{
    $search = app(MachineSearchService::class);
    $fuzzy = app(\App\Support\FuzzyArabicMatcher::class);
    $normalized = trim($search->normalizeSearchText($message));

    if ($normalized === '' || $this->wordCount($normalized) > 3) {
        return false;
    }

    $matches = 0;

    foreach ($lastMachines as $machine) {
        $name = $search->normalizeSearchText(
            trim($this->machineBrandName($machine) . ' ' . $machine->name)
        );

        if ($name !== '' && $fuzzy->containsFuzzyPhrase($name, $normalized)) {
            $matches++;
        }
    }

    return $matches > 0 && $matches < $lastMachines->count();
}

private function isVariantNarrowingReply(string $message): bool
{
    $m = $this->normalizeText($message);

    return app(\App\Support\FuzzyArabicMatcher::class)->containsAnyFuzzyPhrase($m, [
        'استيراد',
        'فرز تاني',
        'فرز ثاني',
        'اصلي',
        'اصليه',
        'محلي',
        'محليه',
        'cc200',
    ]);
}

private function isPureFollowUp(string $message): bool
{
    $m = $this->normalizeText($message);

    return $this->containsAny($m, [
    'قسطها',
'قسطه',
'تقسيطها',
'تقسيطه',
'القسط كام',
'قسط كام',
'شهري',
'شهريا',
'كل شهر',
        'صورها',
        'صورتها',
        'شكلها',
        'اشوفها',
        'عايز اشوفها',
        'عايز صورها',
        'هات صورها',
        'ابعت صورها',
        'وريني صورها',
        'الوانها',
        'سعرها',
        'كام سعرها',
        'بكام',
        'احسبهالي',
'احسبهالى',
'احسبها',
'احسبلي',
'احسبلى',
'عايزها على سنه',
'عايزها علي سنه',
'عايزه على سنه',
'عايزه علي سنه',
'على سنه',
'علي سنه',
'على سنة',
'علي سنة',
'سنه',
'سنة',
'سنتين',
'على سنتين',
'علي سنتين',
'18 شهر',
'24 شهر',
'36 شهر',
    ]);
}
private function handleBrandModels(WhatsappConversation $conversation, Collection $machines, string $message): array
{
    $brandName = $this->machineBrandName($machines->first()) ?: 'الشركة';

    $names = $machines
        ->map(fn (Machine $machine) => '- ' . $this->machineDisplayName($machine))
        ->implode("\n");

$reply = $this->renderMemoryOrDefault('رد اختيار موديل من براند', [
    'brand_name' => $brandName,
    'machine_list' => $names,
], "عندي من {$brandName} الموديلات دي:\n{$names}\n\nتحب أبعتهالك صور ولا سعر أنهي موديل؟");
    $this->saveOutgoing($conversation, $reply, [
        'source' => 'brand_models_list',
        'message' => $message,
        'machine_ids' => $machines->pluck('id')->values()->all(),
        'machine_names' => $machines->pluck('name')->values()->all(),
    ]);

    $this->rememberMachines($conversation, $machines);

    return array_merge($this->textResult($reply), $this->machineMeta($machines));
}
private function machineBrandName(Machine $machine): string
{
    if (method_exists($machine, 'brand')) {
        return trim((string) ($machine->brand?->name ?? ''));
    }

    return '';
}

private function renderMemory(string $title, array $variables = []): ?string
{
    if (! class_exists(\App\Services\AiMemoryResolver::class)) {
        return null;
    }

    $memory = app(\App\Services\AiMemoryResolver::class)->memoryByExactTitle($title);

    if (! $memory) {
        /*
         * Memory titles are effectively an API here - renaming one in
         * Filament silently drops the reply back to a hardcoded default
         * with no error anywhere. Log it so a broken title is visible.
         */
        Log::warning('ai_memory_title_miss', ['title' => $title]);

        return null;
    }

    $reply = app(\App\Services\AiReplyBuilder::class)
        ->fromMemories(collect([$memory]), $variables, '');

    $reply = trim((string) $reply);

    return $reply !== '' ? $reply : null;
}

private function renderMemoryOrDefault(string $title, array $variables, string $default): string
{
    return $this->renderMemory($title, $variables) ?: $default;
}


/*الاستعلام عن الطلب*/
private function handleApplicationStatus(WhatsappConversation $conversation): array
{
    $existing = \App\Models\InstallmentRequest::query()
        ->where('whatsapp_conversation_id', $conversation->id)
        ->latest('id')
        ->first();

    if (! $existing) {
        $reply = 'مش لاقيين طلب تقسيط باسمك يا فندم. لو حابب تقدم، ابعتلي اسم الموديل اللي عايزه.';
    } else {
        $reply = match ($existing->status) {
            'pending'        => "طلبك رقم #{$existing->id} لسه تحت المراجعة، وهنتواصل معاك أول ما نخلص. مش محتاج تعمل طلب جديد دلوقتي.",
            'needs_more_info'=> "طلبك رقم #{$existing->id} محتاج بيانات أو مستندات إضافية. برجاء التواصل مع المعرض لاستكمال المطلوب.",
            'approved'       => "ألف مبروك يا فندم! 🎉 طلبك رقم #{$existing->id} تمت الموافقة عليه. برجاء التوجه إلى الفرع لاستكمال باقي الإجراءات.",
            'paused'         => "طلبك رقم #{$existing->id} متوقف مؤقتًا. برجاء التواصل مع المعرض لاستكمال المطلوب.",
            'rejected'       => "للأسف طلبك رقم #{$existing->id} اترفض. لو عايز تاخد تفاصيل أكتر تواصل مع المعرض.",
            'canceled'       => "طلبك رقم #{$existing->id} اتلغى. لو عايز تعمل طلب جديد، ابعتلي اسم الموديل.",
            default          => "طلبك رقم #{$existing->id} حالته: {$existing->status}. هنتواصل معاك قريبًا.",
        };
    }

    // لا نغير pending_question ولا نغير last_topic — فقط نرد على السؤال
    return $this->textReply($conversation, $reply);
}

/*القسط*/
private function handleInstallmentSystem(
    WhatsappConversation $conversation,
    string $message
): array {

    $reply = $this->renderMemory('رد نظام التقسيط')
        ?: "التقسيط عندنا متاح للموظفين وأصحاب الأنشطة وأصحاب المعاشات والمهن الحرة.\n\n"
        . "عندنا نظامين:\n"
        . "- نظام 20% سنويًا، وفيه 7% مصاريف إدارية.\n"
        . "- نظام 30% سنويًا، بدون مصاريف إدارية.\n\n"
        . "المدد المتاحة: 12 أو 18 أو 24 أو 36 شهر.\n"
        . "ولو حابب أحسبلك القسط ابعتلي المدة، ولو مكنة مختلفة ابعتلي اسمها.";

    $this->saveOutgoing($conversation, $reply, [
        'source' => 'installment_system',
        'message' => $message,
    ]);

$this->updateConversationState($conversation, 'installment_system', null, [
    'machine_ids' => $conversation->last_machine_ids ?? [],
]);
    return $this->textResult($reply);
}


private function isInstallmentSystemIntent(string $m): bool
{
    return $this->containsAny($m, [
        'نظام التقسيط',
        'انظمه التقسيط',
        'انظمة التقسيط',
        'شروط التقسيط',
        'ورق التقسيط',
        'المستندات',
        'بتقسطوا ازاي',
        'بقسط ازاي',
        'ازاي اقسط',
        'ايه نظام القسط',
        'ايه نظام التقسيط',
        'التقسيط عندكم',
        'انظمة اية',
'انظمه ايه',
'ايه الانظمه المتاحه',
'ايه الانظمة المتاحة',
'نظام القسط ايه',
'بتقسطوا تبع ايه',
'شركات التمويل',

'ايه الانظمه المتاحه',
'ايه الانظمة المتاحة',
'الانظمه المتاحه',
'الانظمة المتاحة',
'بتقسطوا تبع ايه',
'بتقسطو تبع ايه',
'تقسيط تبع ايه',
'انظمة ايه',
'انظمه ايه',
'انظمة التقسيط ايه',
'انظمه التقسيط ايه',
'بتقسطو ازاي',
'بتقسّطوا ازاي',
'بتقسط ازاي',
'التقسيط ازاي',
'عايز اعرف التقسيط',
'عايز اعرف نظام التقسيط',
'ايه نظامكم في التقسيط',
'ايه نظامكم',
'انظمتكم ايه',
'انظمتكو ايه',
    ]);
}

private function isInstallmentCalcIntent(string $m): bool
{
    return $this->containsAny($m, [
        'قسطها',
        'قسطه',
        'القسط كام',
        'قسط كام',
        'تقسيطها كام',
        'تقسيطه كام',
        'شهري',
        'شهريا',
        'كل شهر',
        'مقدم',
        'بدون مقدم',
        'من غير مقدم',
        'على سنة',
        'علي سنه',
        'على سنتين',
        'علي سنتين',
        '12 شهر',
        '18 شهر',
        '24 شهر',
        '36 شهر',
        'احسبهالي',
'احسبهالى',
'احسبها',
'احسبلي',
'احسبلى',
'عايزها على',
'عايزها علي',
'عايزه على',
'عايزه علي',
    ]);
}



private function handleInstallmentCalc(
    WhatsappConversation $conversation,
    Collection $machines,
    string $message,
    array $plan = []
): array {
    $aiIntent = $plan;
    $parsed = app(InstallmentTextParser::class)->parse($message);
    $parsed = $this->applyAiParsedInstallment($parsed, $aiIntent);

    if ($machines->isEmpty()) {
        $last = $this->lastMachinesFromConversation($conversation);

        if ($last->isNotEmpty()) {
            $machines = $last;
        }
    }

    if ($machines->isEmpty()) {
        $this->updateConversationState($conversation, 'installment_calc', 'choose_machine');

        $reply = $this->renderMemory('رد طلب المكنة لحساب القسط')
            ?: 'تمام يا فندم، ابعتلي اسم المكنة اللي عايز أحسب قسطها.';

        return $this->textReply($conversation, $reply);
    }

    $months = $parsed['months'] ?? null;

    if (! $months) {
        $this->rememberMachines($conversation, $machines);
        $this->updateConversationState($conversation, 'installment_calc', 'choose_months', [
            'machine_ids' => $machines->pluck('id')->values()->all(),
        ]);

        $reply = $this->renderMemory('رد طلب مدة التقسيط')
            ?: 'تمام يا فندم، تحب التقسيط على كام شهر؟ 12 ولا 18 ولا 24 ولا 36؟';

        return $this->textReply($conversation, $reply);
    }

    $validMonths = $this->validMonthsForMachines($machines);

    if (! empty($validMonths) && ! in_array((int) $months, $validMonths, true)) {
        $this->rememberMachines($conversation, $machines);
        $this->updateConversationState($conversation, 'installment_calc', 'choose_months', [
            'machine_ids' => $machines->pluck('id')->values()->all(),
        ]);

        $list = implode(' / ', $validMonths);

        return $this->textReply(
            $conversation,
            "المدة دي مش متاحة يا فندم، مدد التقسيط المتاحة للمكنة دي: {$list} شهر. تحب تقسط على قد ايه؟"
        );
    }

    $deposit = (float) ($parsed['deposit'] ?? 0);
    $system = (string) ($parsed['system'] ?? '20');

    /*
     * لو نفس الطلب بالظبط اتكرر (نفس المدة/النظام/المقدم)، الأرقام
     * المفروض تفضل زي ما هي - ده مش تكرار غلط، العميل فعلاً بيسأل نفس
     * السؤال. لكن رد نفس الجملة حرفيًا مرتين ورا بعض حسّاس آلي. بنغيّر
     * جملة الافتتاح بس (مش الأرقام) عشان يحس إن حد بيرد عليه فعلاً.
     */
    $payload = $conversation->context_payload ?? [];
    $currentMachineIds = $machines->pluck('id')->sort()->values()->all();
    $previousMachineIds = collect($payload['last_calc_machine_ids'] ?? [])->sort()->values()->all();

    /*
     * "بالظبط زي الأول" لازم يبقى معناه فعلاً "نفس السؤال اللي فات" -
     * نفس المدة/النظام/المقدم مش كفاية لوحدها، لازم كمان تبقى نفس
     * المكنة. من غيرها، سؤال قسط على مكنة تانية بنفس عدد الشهور (زي 12
     * شهر) كان بيترد عليه وكأنه تكرار لسؤال قديم عن مكنة مختلفة تمامًا.
     */
    $isRepeatOfLastCalc = $payload !== []
        && array_key_exists('last_months', $payload)
        && (int) $payload['last_months'] === (int) $months
        && (string) ($payload['last_system'] ?? '20') === $system
        && (float) ($payload['last_deposit'] ?? 0) === $deposit
        && $previousMachineIds === $currentMachineIds;

    $repeatStreak = $isRepeatOfLastCalc ? ((int) ($payload['installment_repeat_streak'] ?? 0) + 1) : 0;

    $isFreelance = app(ApplicationHandler::class)->categorizeIncome(
        (string) ($payload['application']['job_type'] ?? ''),
        (string) ($payload['application']['income_proof'] ?? '')
    ) === 'freelance';

    $calculations = app(InstallmentCalculator::class)
        ->calculateMany($machines, (int) $months, $deposit, $system, $isFreelance);

    $built = app(InstallmentVariablesBuilder::class)->build($calculations);

    if (! ($built['ok'] ?? false)) {
        $reply = $this->renderMemory('رد عدم توفر سعر التقسيط')
            ?: 'القسط محتاج تأكيد يا فندم لأن سعر التقسيط مش متسجل للموديل ده.';

        return $this->textReply($conversation, $reply);
    }

    $variables = $built['variables'];

    $openers = [
        "تمام يا فندم، القسط على {$variables['months']} شهر {$variables['deposit_text']}:",
        "أه تمام، زي ما قلتلك يا فندم، القسط على {$variables['months']} شهر {$variables['deposit_text']} برضو:",
        "بالظبط زي الأول يا فندم، القسط على {$variables['months']} شهر {$variables['deposit_text']}:",
    ];

    $opener = $isRepeatOfLastCalc
        ? $openers[1 + ($repeatStreak % 2)]
        : $openers[0];

    $defaultReply =
        "{$opener}\n\n" .
        "{$variables['installment_list']}\n\n" .
        "{$variables['admin_fee_text']}";

    $reply = $this->renderMemoryOrDefault(
        'رد حساب القسط',
        $variables,
        $defaultReply
    );

    // Same guarded rewording as the cash-price path above - every جنيه in
    // here comes from InstallmentCalculator and has to survive verbatim.
    $reply = app(AiReplyPhraser::class)->phrase($reply, [
        'context' => 'حساب قسط',
        'must_keep' => $machines->map(fn (Machine $machine) => $this->machineDisplayName($machine))->all(),
    ]);

    $this->saveOutgoing($conversation, $reply, [
        'source' => 'installment_calc_ai_state',
        'message' => $message,
        'ai_intent' => $aiIntent,
        'parsed' => $parsed,
        'calculations' => $calculations,
        'machine_ids' => $machines->pluck('id')->values()->all(),
        'machine_names' => $machines->pluck('name')->values()->all(),
    ]);

    $this->rememberMachines($conversation, $machines);
    $this->updateConversationState($conversation, 'installment_calc', null, [
        'last_months' => $months,
        'last_system' => $system,
        'last_deposit' => $deposit,
        'last_calc_machine_ids' => $currentMachineIds,
        'installment_repeat_streak' => $repeatStreak,
    ]);

    return array_merge($this->textResult($reply), $this->machineMeta($machines));
}

/**
 * كل مكنة مرتبطة بـ IDs في installment_systems (array)، وكل نظام تقسيط
 * له خطط (plans) فيها months محددة. برجع union لكل المدد المتاحة فعليًا
 * عبر كل المكن الممررة، عشان العميل ميكتبش رقم شهور من دماغه.
 */
private function validMonthsForMachines(Collection $machines): array
{
    $systemIds = $machines
        ->flatMap(function (Machine $machine) {
            $ids = $machine->installment_systems ?? [];

            return is_array($ids) ? $ids : [];
        })
        ->filter()
        ->unique()
        ->values()
        ->all();

    if (empty($systemIds)) {
        return [];
    }

    return InstallmentSystem::query()
        ->whereIn('id', $systemIds)
        ->get()
        ->flatMap(fn (InstallmentSystem $system) => collect($system->plans ?? [])->pluck('months'))
        ->map(fn ($m) => (int) $m)
        ->filter(fn ($m) => $m > 0)
        ->unique()
        ->sort()
        ->values()
        ->all();
}

private function extractRequestedBrand(string $message): ?array
{
    $m = $this->normalizeText($message);

    $brands = \App\Models\Brand::all();

    foreach ($brands as $brand) {
        $name = trim((string) $brand->name);

        if ($name !== '' && str_contains($m, $this->normalizeText($name))) {
            return [
                'id' => $brand->id,
                'name' => $name,
            ];
        }
    }

    return null;
}


private function filterMachinesByRequestedBrand(
    Collection $machines,
    string $message
): array {

    $requestedBrand = $this->extractRequestedBrand($message);

    if (! $requestedBrand) {
        return [
            'brand_requested' => false,
            'machines' => $machines,
        ];
    }

    $filtered = $machines
        ->filter(fn (Machine $machine)
            => (int) $machine->brand_id === (int) $requestedBrand['id'])
        ->values();

    return [
        'brand_requested' => true,
        'brand_id' => $requestedBrand['id'],
        'brand_name' => $requestedBrand['name'],
        'machines' => $filtered,
        'original_machines' => $machines,
    ];
}
private function extractMachineSearchTextForInstallment(string $message): string
{
    $text = $this->normalizeText($message);

    $text = preg_replace('/\b(?:عايز|عاوز|محتاج|احسب|احسبلي|حساب|قسط|القسط|تقسيط|تقسيطها|قسطها|قسطه|كام|بكام)\b/u', ' ', $text);

$text = preg_replace('/\b(?:علي|على)\s*(?:سنه|سنة|سنتين|\d+\s*(?:شهر|شهور))\b/u', ' ', $text);
$text = preg_replace('/\b(?:لمده|لمدة|مدة)\s*(?:سنه|سنة|سنتين|\d+\s*(?:شهر|شهور))\b/u', ' ', $text);
$text = preg_replace('/\b\d+\s*(?:شهر|شهور)\b/u', ' ', $text);
$text = preg_replace('/\b(?:سنه|سنة|سنتين|شهر|شهور)\b/u', ' ', $text);

    $text = preg_replace('/\b(?:بمقدم|مقدم|هدفع|ادفع|دافع)\s*\d+(?:\.\d+)?\b/u', ' ', $text);
    $text = preg_replace('/\b(?:بدون مقدم|من غير مقدم|مفيش مقدم|صفر مقدم)\b/u', ' ', $text);

    $text = preg_replace('/\b(?:شهري|شهريا|كل شهر|نظام|مصاريف|اداريه|ادارية)\b/u', ' ', $text);

    $text = preg_replace('/\s+/u', ' ', $text);

    return trim($text) ?: $message;
}



private function filterMachinesByRequiredNumbers(Collection $machines, string $message): Collection
{
    preg_match_all('/\d+/u', $this->normalizeText($message), $matches);

    $numbers = array_values(array_unique($matches[0] ?? []));

    if (empty($numbers) || $machines->count() <= 1) {
        return $machines;
    }

    $filtered = $machines->filter(function (Machine $machine) use ($numbers) {
        $name = $this->normalizeText((string) $machine->name);

        foreach ($numbers as $number) {
            if (! preg_match('/(?:^|\D)' . preg_quote($number, '/') . '(?:\D|$)/u', $name)) {
                return false;
            }
        }

        return true;
    })->values();

    return $filtered->isNotEmpty() ? $filtered : $machines;
}



private function narrowMachinesByVariant(Collection $machines, string $message): Collection
{
    $m = $this->normalizeText($message);

    if ($machines->count() <= 1) {
        return $machines;
    }

    $fuzzy = app(\App\Support\FuzzyArabicMatcher::class);

    if ($fuzzy->containsAnyFuzzyPhrase($m, ['فرز تاني', 'فرز ثاني'])) {
        $filtered = $machines->filter(fn ($machine) =>
            str_contains($this->normalizeText($machine->name), 'فرز تاني')
            || str_contains($this->normalizeText($machine->name), 'فرز ثاني')
        )->values();

        return $filtered->isNotEmpty() ? $filtered : $machines;
    }

    if ($fuzzy->containsFuzzyPhrase($m, 'استيراد')) {
        $filtered = $machines->filter(function ($machine) {
            $name = $this->normalizeText($machine->name);

            return str_contains($name, 'استيراد')
                && ! str_contains($name, 'فرز تاني')
                && ! str_contains($name, 'فرز ثاني');
        })->values();

        return $filtered->isNotEmpty() ? $filtered : $machines;
    }

    /*
     * "اصلي"/"محلي" were already recognised as narrowing signals by
     * isVariantNarrowingReply() (used to decide THAT a short reply is a
     * narrowing reply), but this function - the one that actually filters
     * the machine list - had no matching branch for them, only for
     * استيراد/فرز تاني. A query like "دايو 4 اصلي" kept both دايو 4 and
     * دايو 4 اصلي because family search matches both on the shared "4"
     * token and nothing here ever filtered by "اصلي".
     */
    if ($fuzzy->containsAnyFuzzyPhrase($m, ['اصلي', 'اصليه'])) {
        $filtered = $machines->filter(fn ($machine) =>
            str_contains($this->normalizeText($machine->name), 'اصلي')
        )->values();

        return $filtered->isNotEmpty() ? $filtered : $machines;
    }

    if ($fuzzy->containsAnyFuzzyPhrase($m, ['محلي', 'محليه'])) {
        $filtered = $machines->filter(fn ($machine) =>
            str_contains($this->normalizeText($machine->name), 'محلي')
        )->values();

        return $filtered->isNotEmpty() ? $filtered : $machines;
    }

    $search = app(MachineSearchService::class);
    $normalized = trim($search->normalizeSearchText($message));

    if ($normalized !== '' && $this->wordCount($normalized) <= 3) {
        $filtered = $machines->filter(function (Machine $machine) use ($search, $normalized, $fuzzy) {
            $name = $search->normalizeSearchText(
                trim($this->machineBrandName($machine) . ' ' . $machine->name)
            );

            return $name !== '' && $fuzzy->containsFuzzyPhrase($name, $normalized);
        })->values();

        if ($filtered->isNotEmpty() && $filtered->count() < $machines->count()) {
            return $filtered;
        }
    }

    return $machines;
}




private function chooseIntent(string $ruleIntent, array $aiIntent): string
{
    $ai = $aiIntent['intent'] ?? 'unknown';
    $confidence = (float) ($aiIntent['confidence'] ?? 0);

    if (in_array($ai, [
        'price',
        'images',
        'installment_calc',
        'installment_system',
        'brand_models',
    ], true) && $confidence >= 0.55) {
        return $ai;
    }

    return $ruleIntent;
}

private function applyAiParsedInstallment(array $parsed, array $aiIntent): array
{
    if (! empty($aiIntent['months'])) {
        $parsed['months'] = (int) $aiIntent['months'];
    }

    if (array_key_exists('deposit', $aiIntent) && $aiIntent['deposit'] !== null) {
        $parsed['deposit'] = (float) $aiIntent['deposit'];
        $parsed['deposit_mentioned'] = true;
    }

    if (! empty($aiIntent['system']) && in_array((string) $aiIntent['system'], ['20', '30'], true)) {
        $parsed['system'] = (string) $aiIntent['system'];
    }

    return $parsed;
}

private function updateConversationState(
    WhatsappConversation $conversation,
    ?string $topic = null,
    ?string $pendingQuestion = null,
    array $payload = []
): void {
    $currentPayload = $conversation->context_payload ?? [];

    if (is_string($currentPayload)) {
        $currentPayload = json_decode($currentPayload, true) ?: [];
    }

    if (! is_array($currentPayload)) {
        $currentPayload = [];
    }

    $mergedPayload = $this->mergeContextPayload($currentPayload, $payload);

    /*
     * لو طلب تقديم شغال بالفعل (pending_question حاليًا application_missing_data
     * أو application_documents) وحد من الـ handlers الجانبية (سعر/صور/حساب
     * قسط) نادى الدالة دي عشان يسجل بيانات إضافية بس (زي آخر مكنة اتحسبلها
     * قسط)، من غير ما يقصد يقفل أو يغير الطلب نفسه - مينفعش نمسح last_topic
     * وpending_question بتوع الطلب. ده كان بالظبط السبب إن سؤال جانبي عن
     * مكنة تانية وسط طلب تقديم كان بيلغي "احنا لسه بنجمع مستندات" تمامًا،
     * فالرسالة اللي بعدها بترجع تبدأ الطلب من الأول على المكنة الغلط.
     */
    $applicationInProgress = in_array(
        $conversation->pending_question ?? null,
        ['application_missing_data', 'application_documents'],
        true
    );

    $callerTargetsApplication = in_array(
        $pendingQuestion,
        ['application_missing_data', 'application_documents'],
        true
    );

    if ($applicationInProgress && ! $callerTargetsApplication) {
        $conversation->forceFill(['context_payload' => $mergedPayload ?: null])->save();

        return;
    }

    $conversation->forceFill([
        'last_topic' => $topic,
        'pending_question' => $pendingQuestion,
        'context_payload' => $mergedPayload ?: null,
    ])->save();
}

private function mergeContextPayload(array $current, array $updates): array
{
    foreach ($updates as $key => $value) {
        if (
            array_key_exists($key, $current)
            && is_array($current[$key])
            && is_array($value)
            && ! array_is_list($current[$key])
            && ! array_is_list($value)
        ) {
            $current[$key] = $this->mergeContextPayload($current[$key], $value);
            continue;
        }

        $current[$key] = $value;
    }

    return $current;
}






/*
 * لو العميل رد بكلمة تأكيد قصيرة ("تمام"، "اوك"، "تمام كده") فورًا بعد
 * ما حسبنا له القسط، ده يعني موافقة على الشراء مش سؤال سعر جديد.
 */
private function isBareConfirmation(string $normalizedMessage): bool
{
    $normalized = trim($normalizedMessage);

    return in_array($normalized, [
        'تمام',
        'تمام كده',
        'تمام يافندم',
        'اوك',
        'ok',
        'اه تمام',
        'ايوه تمام',
        'موافق',
        'ماشي',
        'ماشي تمام',
        'حلو',
        'تمام كدا',
        'اه',
        'ايوه',
        'ايوا',
    ], true);
}

private function isApplicationIntent(string $normalizedMessage, WhatsappConversation $conversation): bool
{
    if (($conversation->pending_question ?? null) === 'application_missing_data') {
        return true;
    }

    return str_contains($normalizedMessage, 'اقدم')
        || str_contains($normalizedMessage, 'تقديم')
        || str_contains($normalizedMessage, 'قدملي')
        || str_contains($normalizedMessage, 'اعمل طلب')
        || str_contains($normalizedMessage, 'امشي في الاجراءات')
        || str_contains($normalizedMessage, 'اكمل اجراءات')
        || str_contains($normalizedMessage, 'عايز اقدم')
        || str_contains($normalizedMessage, 'عاوز اقدم')
        || str_contains($normalizedMessage, 'اشتري')
        || str_contains($normalizedMessage, 'شراء')
        || str_contains($normalizedMessage, 'المطلوب')
        || str_contains($normalizedMessage, 'اعمل ايه');
}
}

