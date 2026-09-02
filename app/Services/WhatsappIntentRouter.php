<?php

namespace App\Services;

use App\Models\InstallmentSystem;
use App\Models\Machine;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
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

        /*
         * لقطة لآخر الردود اللي خرجت *قبل* الدور ده. لازم تتاخد هنا
         * بالظبط، لأن الهاندلر جوه handleInternal() بيسجّل رده بنفسه
         * فبعد ما يرجّع مش هينفع نفرّق بين "رد النهاردة" و"رد امبارح".
         */
        $lastOutgoingIdBefore = (int) $conversation->messages()
            ->where('direction', 'outgoing')
            ->max('id');
        $previousOutgoing = $conversation->messages()
            ->where('direction', 'outgoing')
            ->latest('id')
            ->take(3)
            ->pluck('message')
            ->filter()
            ->values()
            ->all();

        $result = $this->handleInternal($conversation, $message, $mediaItems);

        $conversation->refresh();

        $result = $this->guardAgainstLoop(
            $conversation,
            $result,
            $message,
            $previousOutgoing,
            $lastOutgoingIdBefore,
            $missingFieldsBefore
        );

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
 * بيفكّر القايمة اللي عرضناها على العميل في الدور اللي فات، عشان رده
 * الجاي يتحل عليها.
 */
private function rememberApplicationChoices(WhatsappConversation $conversation, Collection $machines): void
{
    $context = is_array($conversation->context_payload) ? $conversation->context_payload : [];
    $context['application_machine_choices'] = $machines->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

    $conversation->forceFill(['context_payload' => $context])->save();
}

/**
 * بيحاول يطابق رسالة العميل على القايمة اللي عرضناها. بيرجّع Collection
 * فيها مكنة واحدة لو لقى مطابقة أكيدة، وnull غير كده.
 *
 * بيقبل كمان الرد بالترتيب ("الأول"، "التاني"، "١") لأن ده رد طبيعي
 * تمامًا على قايمة مرقّمة والعميل بيستخدمه كتير.
 */
private function resolveApplicationChoice(WhatsappConversation $conversation, string $message): ?Collection
{
    $context = is_array($conversation->context_payload) ? $conversation->context_payload : [];
    $choices = $context['application_machine_choices'] ?? null;

    if (! is_array($choices) || count($choices) < 2) {
        return null;
    }

    $machines = Machine::query()->whereIn('id', $choices)->get();

    if ($machines->isEmpty()) {
        return null;
    }

    $normalized = $this->normalizeText($message);

    if ($normalized === '') {
        return null;
    }

    $ordinals = [
        1 => ['الاول', 'الاولانى', 'الاولاني', 'اول واحده', 'رقم 1', '1', 'الأول'],
        2 => ['التانى', 'التاني', 'الثانى', 'الثاني', 'رقم 2', '2'],
        3 => ['التالت', 'الثالث', 'رقم 3', '3'],
    ];

    foreach ($ordinals as $position => $words) {
        foreach ($words as $word) {
            if ($normalized === $this->normalizeText($word)) {
                $picked = $machines->values()->get($position - 1);

                if ($picked) {
                    $this->clearApplicationChoices($conversation);

                    return collect([$picked]);
                }
            }
        }
    }

    $matched = $machines->filter(function (Machine $machine) use ($normalized) {
        $name = $this->normalizeText($this->machineDisplayName($machine));
        $bare = $this->normalizeText($machine->name);

        return $name !== '' && (str_contains($normalized, $name) || str_contains($normalized, $bare));
    });

    if ($matched->count() === 1) {
        $this->clearApplicationChoices($conversation);

        return $matched->values();
    }

    return null;
}

private function clearApplicationChoices(WhatsappConversation $conversation): void
{
    $context = is_array($conversation->context_payload) ? $conversation->context_payload : [];

    if (! array_key_exists('application_machine_choices', $context)) {
        return;
    }

    unset($context['application_machine_choices']);

    $conversation->forceFill(['context_payload' => $context])->save();
}

/**
 * بيحفظ أي بيانات طلب موجودة في رسالة وصلت قبل ما الموديل يتحدد.
 * best-effort - أي فشل هنا ميوقفش المحادثة.
 */
private function bankApplicationDataEarly(WhatsappConversation $conversation, string $message): void
{
    try {
        $context = is_array($conversation->context_payload) ? $conversation->context_payload : [];
        $application = is_array($context['application'] ?? null) ? $context['application'] : [];

        $updated = app(ApplicationHandler::class)->bankVolunteeredData(
            $conversation,
            $application,
            $message,
            null
        );

        if ($updated === $application) {
            return;
        }

        $context['application'] = $updated;

        $conversation->forceFill([
            'last_topic' => 'application',
            'context_payload' => $context,
        ])->save();
    } catch (\Throwable $e) {
        Log::warning('early application banking failed', [
            'conversation_id' => $conversation->id,
            'error' => $e->getMessage(),
        ]);
    }
}

/**
 * نقطة الاختناق الوحيدة لمنع اللوب. أي رد خارج من أي هاندلر بيعدي من
 * هنا، فمش ممكن هاندلر جديد "ينسى" يستخدم المانع زي ما كان بيحصل قبل
 * كده لما كان الفحص في handleAiFallback() بس.
 *
 * لو الرد مكرر: بنستبدله بنسخة متصعّدة (إعادة صياغة -> باب خروج ->
 * تحويل لموظف) *وبنحدّث الصف اللي الهاندلر سجّله فعلًا* عشان اللي في
 * الداشبورد يبقى هو اللي العميل شافه بالظبط.
 */
private function guardAgainstLoop(
    WhatsappConversation $conversation,
    array $result,
    string $message,
    array $previousOutgoing,
    int $lastOutgoingIdBefore,
    ?array $missingFieldsBefore = null
): array {
    $reply = trim((string) ($result['reply'] ?? ''));

    if ($reply === '' || ($result['handled'] ?? false) !== true) {
        return $result;
    }

    /*
     * الطلب اتقدّم فعلًا في الدور ده (بيانة ناقصة اتملت)، فالرد ده مش
     * لوب حتى لو شكله قريب من اللي قبله - أسئلة البيانات المتتالية
     * بتشترك في نفس القالب بطبيعتها ("محتاج منك دلوقتي كذا"). من غير
     * الشرط ده كان ممكن طلب ماشي صح يتحوّل لموظف عند التالت سؤال.
     */
    $missingAfter = $conversation->context_payload['missing_fields'] ?? null;

    if (
        is_array($missingFieldsBefore)
        && is_array($missingAfter)
        && count($missingAfter) < count($missingFieldsBefore)
    ) {
        return $result;
    }

    // التحويل نفسه رسالة متكررة بطبيعتها - مش لوب.
    if (($conversation->status ?? 'open') === 'awaiting_agent') {
        return $result;
    }

    // رد فيه صور أو أكتر من رسالة مش سؤال عالق، سيبه.
    if (! empty($result['images']) || ! empty($result['image_items'])) {
        return $result;
    }

    try {
        $verdict = app(ConversationLoopGuard::class)->inspect(
            $conversation,
            $reply,
            $previousOutgoing,
            $message
        );
    } catch (\Throwable $e) {
        Log::warning('loop guard failed', [
            'conversation_id' => $conversation->id,
            'error' => $e->getMessage(),
        ]);

        return $result;
    }

    $this->lastTurnRepetitionScore = $verdict['score'] ?? null;

    if ($verdict['streak'] === 0) {
        return $result;
    }

    if ($verdict['handoff'] === true) {
        $this->deleteOutgoingSince($conversation, $lastOutgoingIdBefore);

        return $this->handoffToAgent($conversation, $message, 'loop_guard_exhausted');
    }

    $replacement = trim((string) ($verdict['reply'] ?? ''));

    if ($replacement === '' || $replacement === $reply) {
        return $result;
    }

    $conversation->messages()
        ->where('direction', 'outgoing')
        ->where('id', '>', $lastOutgoingIdBefore)
        ->latest('id')
        ->take(1)
        ->get()
        ->each(function ($row) use ($replacement) {
            $payload = is_array($row->payload) ? $row->payload : [];
            $payload['loop_guard_rewritten'] = true;
            $row->forceFill(['message' => $replacement, 'payload' => $payload])->save();
        });

    $result['reply'] = $replacement;

    if (isset($result['replies']) && is_array($result['replies']) && count($result['replies']) === 1) {
        $result['replies'] = [$replacement];
    }

    return $result;
}

/**
 * بيمسح الرد اللي الهاندلر سجّله في الدور ده قبل ما نستبدله بتحويل
 * لموظف - عشان الداشبورد ميعرضش رسالة العميل عمره ما شافها.
 */
private function deleteOutgoingSince(WhatsappConversation $conversation, int $lastOutgoingIdBefore): void
{
    $conversation->messages()
        ->where('direction', 'outgoing')
        ->where('id', '>', $lastOutgoingIdBefore)
        ->delete();
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
    $steps = $this->dropAlreadyAnsweredSteps($conversation, $this->lastTurnExtraSteps);

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
            $stepReply = trim($stepResult['reply']);

            /*
             * الرد الأساسي بقى بيغطي كل الموديلات اللي العميل ذكرها في
             * رسالة واحدة (شوف machinesFromEachSegment)، فخطوة إضافية
             * عن نفس الموديل بترجّع نفس السعر تاني في نفس الرسالة -
             * وده بالظبط شكل "البوت بيرد مرتين" اللي بيضايق العميل.
             */
            if (! $this->replySaysTheSameThing($result['reply'] ?? '', $stepReply)) {
                $extraReplies[] = $stepReply;
            }
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
        $primaryReply = trim((string) ($result['reply'] ?? ''));

        /*
         * لما العميل يطلب حاجتين في رسالة واحدة ("سعر دايو ٤ وابعتلي
         * مكانكم فين")، الردين كانوا بيتلزقوا في رسالة واتساب واحدة طويلة
         * - بني آدم مكانّا كان هيبعت رسالتين. replies[] بيخلي كل إجابة
         * رسالة لوحدها عند الإرسال، وreply بيفضل النص المجمّع زي ما هو
         * عشان أي حاجة تانية بتقرا من النتيجة (الداشبورد/الـ API) ما تتكسرش.
         */
        $result['replies'] = array_values(array_filter(array_merge(
            [$primaryReply],
            $extraReplies
        )));

        $result['reply'] = trim($primaryReply . "\n\n" . implode("\n\n", $extraReplies));
    }

    $result['images'] = array_values(array_unique(array_filter($extraImages)));
    $result['image_items'] = $extraImageItems;
    $result['image_groups'] = $extraImageGroups;
    $result['image'] = $result['image'] ?? ($result['images'][0] ?? null);

    return $result;
}

/**
 * الردود دي ثابتة (نفس النص كل مرة) - الفروع، التوصيل، أنظمة التقسيط،
 * شرح المصاريف الإدارية. لو الـ planner حط واحد منهم في steps[] وهو
 * أصلاً اتبعت في دور قريب، معناه إنه قرا طلب قديم من الرسايل السابقة
 * مش من الرسالة الحالية - ولو نفذناه العميل بياخد نفس قايمة الفروع
 * مرتين ورا بعض من غير ما يطلبها.
 */
private const STATIC_INFO_STEP_INTENTS = [
    'branches',
    'delivery_question',
    'installment_system',
    'admin_fee_explanation',
];

private function dropAlreadyAnsweredSteps(WhatsappConversation $conversation, array $steps): array
{
    if (empty($steps)) {
        return $steps;
    }

    $recentIntents = $conversation->messages()
        ->where('direction', 'outgoing')
        ->latest('id')
        ->take(4)
        ->pluck('payload')
        ->map(fn ($payload) => is_array($payload) ? ($payload['intent'] ?? $payload['source'] ?? null) : null)
        ->filter()
        ->values()
        ->all();

    return array_values(array_filter($steps, function (array $step) use ($recentIntents) {
        $intent = $step['intent'] ?? null;

        if (! in_array($intent, self::STATIC_INFO_STEP_INTENTS, true)) {
            return true;
        }

        if (! in_array($intent, $recentIntents, true)) {
            return true;
        }

        Log::info('extra_step_skipped_recently_answered', ['intent' => $intent]);

        return false;
    }));
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

    $answerable = [
        'price',
        'images',
        'installment_calc',
        'installment_total',
        'installment_system',
        'brand_models',
        'admin_fee_explanation',
        'branches',
        'delivery_question',
    ];

    if (! in_array($intent, $answerable, true)) {
        return ['reply' => null, 'images' => []];
    }

    if ($intent === 'installment_system') {
        return $this->handleInstallmentSystem($conversation, $message);
    }

    /*
     * "عاوز اعرف سعر دايو ٤ وابعتلي مكانكم فين" - the second half used to
     * be dropped on the floor: there was no branches intent at all, and a
     * step whose intent is not in the list above returns nothing. Both of
     * these are answered from ai_memories through the free AI path, scoped
     * to the step's own intent so the memory retrieval pulls the branch
     * list / delivery rules instead of whatever the primary intent was.
     */
    if (in_array($intent, ['branches', 'delivery_question'], true)) {
        /*
         * الرد الأساسي (السعر مثلاً) اتبعت خلاص في رسالة لوحدها، فلازم
         * الخطوة دي ترد على جزئها بس - من غير التزكير بالسعر ولا سؤال
         * ختامي تاني. من غير التوجيه ده الـ AI بيشوف الرسالة كاملة
         * ويجاوب عليها من أولها لآخرها تاني.
         */
        $focus = $intent === 'branches'
            ? 'مكان المعرض/الفروع والعناوين واللوكيشن بس'
            : 'موضوع التوصيل بس';

        return $this->handleAiFallback($conversation, $message, null, $intent, $focus);
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
        'installment_total' => $this->handleInstallmentTotal($conversation, $machines, $message, $step),
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
         * المحادثة دي محوّلة لموظف دعم فعلاً - الـ AI مش بيرد ردود
         * موضوعية لحد ما الموظف يقفل التحويل من الداشبورد (status يرجع
         * 'open')، بس مبقاش بيسكت تمامًا: شوف handleWhileAwaitingAgent().
         * الرسالة دي اتسجلت خلاص في WhatsappBotController::incomingMessage()
         * قبل ما الـ job يتعمله process - إعادة تسجيلها هنا كانت بتنتج صف
         * incoming مكرر لكل رسالة توصل أثناء التحويل (يبان في الداشبورد
         * مرتين ويلوّث الـ 15/20 رسالة اللي بتتبعت للـ AI بعد ما الموظف
         * يقفل التحويل).
         */
        if (($conversation->status ?? 'open') === 'awaiting_agent') {
            return $this->handleWhileAwaitingAgent($conversation, $message, $mediaItems);
        }

        /*
         * "." و"؟؟" و"..." مش أسئلة جديدة - دي استعجال على رد لسه
         * بيتكتب. في محادثة الإعلان العميل بعت "طب هو سعرو كام" وبعدها
         * "." و"؟؟"، فطلعوا **تلات ردود** ورا بعض: السعر، ونفس السعر
         * حرفيًا بمقدمة "معلش يمكن سؤالي مكانش واضح"، وتالت رد عام.
         * النقطة اتصنّفت "سؤال عن سعر" أصلاً.
         *
         * والشكر برضه مش سؤال: "تمام" + "شكرا" ورا بعض كانوا بياخدوا
         * رسالتين شكر متطابقتين.
         */
        if ($message !== '' && ! count($mediaItems) && $this->isFillerFollowUp($conversation, $message)) {
            return ['reply' => null, 'images' => []];
        }

        /*
         * العميل بيشتم = المحادثة خرجت عن مسارها، والبوت مش هيصلحها.
         * في محادثة الإعلان العميل رد "الرقم القومي عند امك" و"انطر
         * يعرص"، والبوت رد "لسه مستنى منك الرقم القومي" وفضل يطلب
         * البطاقة - وده اللي فجّر المحادثة لـ 20 رسالة شتيمة. التحويل
         * لموظف بشري في أول إهانة هو الرد الوحيد المحترم.
         */
        if ($message !== '' && $this->messageIsAbusive($message)) {
            Log::info('ai_abuse_handoff', [
                'conversation_id' => $conversation->id,
                'message' => mb_substr($message, 0, 120),
            ]);

            return $this->handoffToAgent($conversation, $message, 'abusive_message');
        }

        if (count($mediaItems) && $this->allMediaAreVoice($mediaItems)) {
            /*
             * بنحاول نفرّغ الفويس لنص ونكمّل المحادثة عادي بدل ما نقول
             * "اكتبلي". مندوبين التوصيل - أهم شريحة عندنا - بيبعتوا فويس
             * وهما على الموتوسيكل، وردنا القديم كان بيوقفهم ويحوّلهم
             * لموظف بعد تلات محاولات.
             */
            $transcript = app(\App\Services\VoiceTranscriptionService::class)->transcribe($mediaItems);

            if ($transcript !== null) {
                $conversation->messages()->create([
                    'direction' => 'incoming',
                    'message' => $transcript,
                    'payload' => ['source' => 'voice_transcript'],
                ]);

                $context = is_array($conversation->context_payload) ? $conversation->context_payload : [];
                unset($context['voice_message_count']);
                $conversation->forceFill(['context_payload' => $context])->save();

                return $this->handleInternal($conversation->refresh(), $transcript, []);
            }

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

        /*
         * "مش فاهم حاجة" كانت بتتحسب طلب موظف بشري وتنهي المحادثة. دي
         * أسوأ قراءة ممكنة للجملة دي: العميل بيقول إن آخر رسالة منّا
         * مكانتش واضحة. الرد الصح إننا نعيد شرح آخر حاجة قلناها بأبسط
         * صياغة - وده اللي موظف حقيقي كان هيعمله.
         */
        if (! count($mediaItems) && $this->isConfusionMessage($message)) {
            $simplified = $this->handleConfusionMessage($conversation, $message);

            if ($simplified !== null) {
                return $simplified;
            }
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
                    /*
                     * installment_system is in here because it was the exact
                     * misfire this guard exists for: "ايه هي المصاريف
                     * الادارية" came back as installment_system with high
                     * confidence, so the safety net never fired and the
                     * customer got the whole "التقسيط عندنا متاح لـ..."
                     * paragraph - which does not contain the answer to what
                     * he asked. The classifier now has admin_fee_explanation
                     * as a real intent; this stays as the belt-and-braces.
                     */
                    in_array($intent, ['general', 'unknown', 'admin_fee_explanation', 'installment_system'], true)
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
         * "اخر القسط هكون دافع كام اجمالي" - لو الكلاسيفاير قراها حساب قسط
         * عادي، الرد بيبقى **نفس** رسالة القسط الشهري حرفيًا تاني والعميل
         * عمره ما يشوف الرقم اللي سأل عنه. الصياغة دي واضحة كفاية إن
         * نميّزها هنا حتى لو الكلاسيفاير واثق في installment_calc.
         */
        if (
            $this->isInstallmentTotalIntent($normalizedMessage)
            && in_array($intent, ['installment_calc', 'installment_system', 'general', 'unknown'], true)
        ) {
            $intent = 'installment_total';
            $plan['intent'] = $intent;
            $plan['needs_clarification'] = false;
            $plan['clarification_question'] = null;
        }

        /*
         * "انا شغال طلبات علي عجله" - العميل بيعرّف بشغله ومركبته، مش
         * بيطلب حسبة قسط. الكلاسيفاير رجّعها installment_calc، فالرسالة
         * كانت رايحة على handleInstallmentCalc وممكن ترجع نفس أرقام
         * القسط اللي اتبعتت قبلها بثواني بدل ما ترد على اللي قاله فعلاً.
         *
         * الشرط ضيق بقصد: جملة تعريف بالشغل، ومفيهاش أي إشارة لحسبة
         * (مقدم/مدة/كلمة قسط أو سعر). لو قال شغله وطلب حسبة في نفس
         * الرسالة، دي تفضل حسبة زي ما هي.
         *
         * ملحوظة: ده بيأثر بره مرحلة التقديم بس - جوّه المرحلة الرسالة
         * أصلاً بتروح لـ ApplicationHandler من الشرط اللي فوق مهما كان
         * تصنيف الكلاسيفاير.
         */
        if (
            in_array($intent, ['installment_calc', 'installment_total', 'installment_system', 'price'], true)
            && ! $mentionsDepositOrMonths
            && $this->statesOwnJobOnly($normalizedMessage)
        ) {
            $intent = 'general';
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
        /*
         * "لو الشخص سأل أي سؤال واحنا في مرحلة الطلب - سؤال على مكنة، أو
         * بره المكنة، أو عن التقديم نفسه - لازم يترد عليه صح."
         * الليستة دي كانت ٦ نوايا بس، فأي سؤال تاني (فين مكانكم، طلبي
         * وصل لفين، سؤال عام) كان بيتبلع ويترد عليه بنفس قايمة البيانات
         * الناقصة - وده أهم سبب إن التقديم بيحس إنه robot. دلوقتي كل نية
         * ليها إجابة حقيقية بتقاطع التقديم، والرد بيرجع للتقديم بعده عن
         * طريق withApplicationResume().
         */
        $answerableDuringApplication = [
            'price',
            'images',
            'installment_system',
            'installment_calc',
            'installment_total',
            'delivery_question',
            'admin_fee_explanation',
            'brand_models',
            'branches',
            'application_status',
        ];

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

        /*
         * سؤال عام وسط التقديم ("ليه محتاجين الرقم القومي؟"، "الورق ده
         * بيروح فين؟"، "لو اترفضت يحصل ايه؟") - الكلاسيفاير بيرجعه
         * general/unknown، وقبل كده ده كان بيتحول لـ intent=application
         * فيترد عليه بنفس قايمة البيانات الناقصة من غير ما يتجاوب خالص.
         * الرد الحر بقى شايف حالة الطلب (applicationStateForPrompt)، فهو
         * يقدر يجاوب السؤال ويكمّل من غير ما يعيد يسأل عن حاجة اتجمعت.
         * بنشترط إنها تبان سؤال فعلاً، مش بيانات - عشان "كيرلس ناجي" أو
         * رقم قومي ما يتفهموش كسؤال.
         */
        $isGeneralQuestionDuringApplication = $applicationIsPending
            && ! $isAnswerToPendingApplicationQuestion
            && in_array($intent, ['general', 'unknown'], true)
            && $this->looksLikeQuestion($message)
            && ! $this->looksLikeApplicationData($message);

        $resumeApplicationAfterAnswer = $isConfidentInterruptingQuestion
            || $isGeneralQuestionDuringApplication;

        if ($isGeneralQuestionDuringApplication) {
            return $this->withApplicationResume(
                $conversation,
                $this->handleAiFallback($conversation, $message, null, 'application'),
                true
            );
        }

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
            /*
             * التقديم بيجمع 8 بيانات شخصية - مينفعش يبدأ والعميل لسه
             * مش مختار مكنة. في محادثة الإعلان العميل كتب "قسط" وهو
             * أصلاً بيسأل عن مكنة كهربائية مش عندنا، فالبوت بدأ يطلب
             * منه "الاسم بالكامل (وفاضل 7 بيانات)" وهو بيقول "مش عارف
             * انهي موديل" و"طيب متاحه ولا لا" - والرد: "لسه مستنى منك
             * الاسم بالكامل".
             *
             * أول مرة بس هي اللي بتتفحص: طلب شغال بالفعل بيكمّل عادي.
             */
            if (! $applicationIsPending && ! $this->hasChosenMachine($conversation, $lastMachines)) {
                return $this->textReply(
                    $conversation,
                    'تمام يا فندم، قبل ما نبدأ إجراءات التقديم محتاج أعرف حضرتك عاوز تقسّط أنهي موتوسيكل؟'
                        . ' قوللي اسم الموديل وأنا أقولك القسط والمطلوب.'
                );
            }

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
        app(\App\Services\ClarificationService::class)->reset(
            $conversation,
            ! $this->isPureGreeting($normalizedMessage)
        );

        /*
         * Only reachable once understanding was confident enough to skip
         * clarification, so a clarification question or an escalation never
         * has extra steps attached to it - see handle()'s appendExtraSteps().
         */
        $this->lastTurnExtraSteps = $plan['steps'] ?? [];

        $machines = $this->resolveMachinesFromPlan($conversation, $message, $plan);

        /*
         * شرط "&& $machines->isNotEmpty()" كان بيسيب أخطر حالة تعدي:
         * رسالة سعر صريحة ("سعرها كام") لما الموديل ما اتحلّش. الـ planner
         * بيرجّع general، والشرط ده كان بيمنع إعادة التصنيف، فالرسالة
         * كانت بتروح لـ handleAiFallback والـ LLM يرد بسعر من دماغه
         * (رد "دايونج سعرها كاش 65,000" وده سعر H250 أصلاً، مش سعرها).
         *
         * إعادة التصنيف دلوقتي بتجري حتى لو المكن فاضي - وساعتها الحارس
         * الحتمي تحت (intent === 'price' && machines->isEmpty()) بيسأل
         * العميل يأكد اسم الموديل بدل ما يديله رقم غلط.
         */
        if (in_array($intent, ['general', 'unknown'], true) && ! $isComplaint) {
            $detected = $this->detectIntent($message);

            /*
             * من غير مكن متعرّف عليه، مينفعش نعيد التصنيف غير للنوايا
             * اللي ليها حارس حتمي بيتعامل مع الحالة الفاضية (السعر).
             * باقي النوايا محتاجة موديل عشان ترد صح، فسيبها تعدي
             * للمسار العام زي ما كانت.
             */
            if ($machines->isNotEmpty() || $detected === 'price') {
                $intent = $detected;
                $plan['intent'] = $intent;
            }
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

        /*
         * "عايز دايون 4 و وينج 200" - موديلين من براندين في رسالة واحدة.
         * البحث كان بيرجّع واحد بس، وفلتر البراند بيشوف إن الموديل اللي
         * رجع (وينج) مش من البراند اللي العميل ذكره (دايو) فيرد
         * "الموديل ده مش متوفر من دايو" - والاتنين موجودين فعلاً!
         *
         * فبنجرّب كل جزء من الرسالة لوحده، ولو طلع أكتر من مكنة يبقى
         * الطلب على أكتر من موديل: نرد عليهم كلهم ومنفلترش على براند
         * واحد.
         */
        $multiModel = $this->machinesFromEachSegment($message);

        if ($multiModel->count() > 1) {
            $machines = $multiModel;
            $brandFiltered = ['machines' => $multiModel, 'brand_requested' => false];
        } else {
            $brandFiltered = $this->filterMachinesByRequestedBrand($machines, $message);
        }

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

            /*
             * لو إحنا اللي عرضنا قايمة موديلات في الدور اللي فات، الرد
             * الجاي لازم يتحل على القايمة دي الأول. من غير ده كان الرد
             * بيترجم من أول وجديد كل دور، فلو العميل بعت اسمه بدل ما
             * يختار كنا بنعرض نفس القايمة تاني... وتالت.
             */
            $resolvedFromChoices = $this->resolveApplicationChoice($conversation, $message);

            if ($resolvedFromChoices) {
                $machines = $resolvedFromChoices;
            }

            if ($machines->isEmpty() || $machines->count() > 1) {
                /*
                 * أي بيانات في الرسالة (اسم، شغل، عنوان، موبايل) بتتحفظ
                 * دلوقتي قبل ما نسأل عن الموديل. قبل كده كانت بتتمسح،
                 * والعميل كان بيعيد كتابتها كلها بعد ما يختار.
                 */
                $this->bankApplicationDataEarly($conversation, $message);
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

                $this->rememberApplicationChoices($conversation, $machines);

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

        /*
         * "مكانكم فين" / "ابعتلي اللوكيشن" - the branch list and its map
         * links live in ai_memories, written as a formatted message, so the
         * free AI path (which is what actually reads memories) answers it.
         * What was missing was ever *routing* here: with no branches intent
         * the planner had to squeeze the question into general/unknown, and
         * as a second request in the same message it was dropped entirely.
         */
        if ($intent === 'branches') {
            return $this->withApplicationResume(
                $conversation,
                $this->handleAiFallback($conversation, $message, null, 'branches'),
                $resumeApplicationAfterAnswer
            );
        }

        if ($intent === 'installment_total') {
            return $this->withApplicationResume(
                $conversation,
                $this->handleInstallmentTotal($conversation, $machines, $message, $plan),
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

        /*
         * "عندكم بينيلي؟" - سؤال توفر. لازم يترد عليه من الداتابيز مش من
         * الـ LLM: الـ LLM كان بينكر براندات موجودة عندنا فعلًا لأنه
         * معندوش كتالوج (شوف CatalogSummaryService). الرد هنا deterministic
         * ومستحيل يهلوس.
         */
        if ($intent === 'availability') {
            if ($machines->isNotEmpty()) {
                return $this->handleBrandModels($conversation, $machines, $message);
            }

            $catalog = app(\App\Services\CatalogSummaryService::class)->brandNames();

            return $this->textReply(
                $conversation,
                "الماركات المتوفرة عندنا دلوقتي:\n- " . implode("\n- ", $catalog)
                . "\n\nتحب أعرفلك أنهي موديل بالظبط يا فندم؟"
            );
        }

        /*
         * "رشحلي حاجة كويسة" / "معايا ٣٠ ألف، فيه إيه؟" / "ينفع بـ ٢٠
         * ألف؟" - أكتر سؤال حقيقي في المعرض، والبوت كان بيرمي السؤال
         * على العميل تاني كل مرة (٣ مرات ورا بعض في التجربة الحقيقية)
         * أو يفهمها غلط على إنها طلب حساب قسط.
         *
         * الرد deterministic من الداتابيز للسبب اللي في كل مكان تاني:
         * الترشيح لازم يكون بأسماء وأسعار حقيقية.
         */
        if ($intent === 'recommendation') {
            $budget = isset($plan['budget']) && $plan['budget'] > 0
                ? (float) $plan['budget']
                : null;

            $recommendation = app(\App\Services\MachineRecommendationService::class)
                ->recommend($conversation, $message, $budget);

            if ($recommendation['machines']->isNotEmpty()) {
                $this->rememberMachines($conversation, $recommendation['machines']);
            }

            return $this->textReply($conversation, $recommendation['reply']);
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
            /*
             * فرق مهم: "مش فاهم اسم الموديل" غير "الموديل ده مش عندنا".
             * عميل في محادثة الإعلان سأل على مكنة كهربائية اسمها Pluto -
             * وإحنا مفيش عندنا ولا مكنة كهربائية أصلاً - فالبوت فضل
             * يقوله "تقصد أنهي موديل؟" وهو رد "مش عارف انهي موديل"،
             * وانتهت المحادثة من غير ما حد يقوله إن ده مش متوفر.
             */
            $unavailable = $this->unavailableCategoryReply($message);

            if ($unavailable !== null) {
                return $this->textReply($conversation, $unavailable);
            }

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
    ?Collection $machines = null,
    ?string $intentOverride = null,
    ?string $focus = null
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
         * كل الأرقام الحقيقية للسيناريو اللي العميل واقف فيه (سعر الكاش،
         * سعر التقسيط، آخر مدة/مقدم/نظام اتحسبوا، القسط الشهري، المصاريف
         * الإدارية، الإجمالي). من غيرها كان الـ AI بيشوف نص ردودنا القديمة
         * بس، فأي سؤال حسابي جديد ("طب الاجمالي كام؟") ما كانش قدامه غير
         * إنه يكرر آخر رسالة - وده بالظبط اللي كان بيحصل.
         */
        'installment_snapshot' => $this->installmentSnapshot($conversation),
        /*
         * Without this the memory builder always saw intent=null, so its
         * intent filter and every intent-based relevance boost were dead
         * on every single turn (visible in ai_memory_retrieval_logs).
         */
        'intent' => $intentOverride ?: $this->lastTurnIntent,
        'step_focus' => $focus,
        'application_state' => $this->applicationStateForPrompt($conversation),
        // Plan task 3.5 - one line about who this is, when we know them.
        'customer_profile' => app(CustomerProfileService::class)->summaryFor($conversation),
        'from' => $conversation->phone ?? null,
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

        if (\App\Support\TransientAiFailurePolicy::actionFor($failures) === \App\Support\TransientAiFailurePolicy::HANDOFF) {
            return $this->handoffToAgent($conversation, $message, 'technical_failure');
        }

        /*
         * الرد القديم هنا كان "ثواني يا فندم، هراجعلك التفاصيل وأرد
         * عليك." - وعد مكانش وراه أي حاجة. الرد ده بيتحسب رد الدور، فالـ
         * job بيتقفل done والمراجعة الموعود بيها مبتحصلش أبدًا؛ في محادثة
         * 253 العميل استنى 8 دقايق وفتح الكلام تاني بنفسه.
         *
         * الرمي هنا هو اللي بيعمل المحاولة التانية فعلًا: worker الرسايل
         * بيرجّع الـ job لـ pending ويعيد توليد الدور من أول وجديد. لو
         * فشل تاني، السطر اللي فوق بيحوّله لموظف - يعني في كل الحالات
         * العميل بياخد رد حقيقي، مش وعد.
         */
        throw new \App\Exceptions\TransientAiFailure(
            'AI reply generation failed transiently: ' . (string) ($result['error'] ?? 'unknown')
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


/**
 * الحسبة الحالية بأرقامها من InstallmentCalculator - نفس المصدر اللي
 * بيطلع منه رد القسط بالظبط، عشان أي رقم الـ AI يقوله في رد حر يبقى
 * متطابق مع اللي بعتناه قبل كده مش تقدير من عنده.
 */
private function installmentSnapshot(WhatsappConversation $conversation): array
{
    $machine = $this->activeMachineFromConversation($conversation);

    if (! $machine) {
        return [];
    }

    $snapshot = [
        'machine_name' => $this->machineDisplayName($machine),
        'cash_price' => $machine->cash_price,
        'installment_price' => $machine->installment_price,
        'available_months' => $this->validMonthsForMachines(collect([$machine])),
    ];

    $payload = $conversation->context_payload ?? [];

    if (! array_key_exists('last_months', $payload)) {
        return $snapshot;
    }

    $months = (int) $payload['last_months'];
    $deposit = (float) ($payload['last_deposit'] ?? 0);
    $system = (string) ($payload['last_system'] ?? '20');

    $isFreelance = app(ApplicationHandler::class)->categorizeIncome(
        (string) ($payload['application']['job_type'] ?? ''),
        (string) ($payload['application']['income_proof'] ?? '')
    ) === 'freelance';

    $calc = app(InstallmentCalculator::class)->calculate($machine, $months, $deposit, $system, $isFreelance);

    if (! ($calc['ok'] ?? false)) {
        return $snapshot;
    }

    $monthly = (int) $calc['monthly_payment'];
    $adminFee = (int) ($calc['admin_fee'] ?? 0);
    $depositDue = (float) ($calc['deposit'] ?? 0) + (float) ($calc['freelance_extra_deposit'] ?? 0);

    return $snapshot + [
        'months' => $months,
        'system' => $system . '%',
        'deposit' => (int) $depositDue,
        'monthly_payment' => $monthly,
        'admin_fee' => $adminFee,
        'installments_total' => $monthly * $months,
        'grand_total' => (int) ($monthly * $months + $adminFee + $depositDue),
        'due_at_pickup' => (int) ($adminFee + $depositDue),
        'first_installment_after_days' => 45,
    ];
}

private function detectIntent(string $message): string
{
    $m = $this->normalizeText($message);

if ($this->isBranchesIntent($m)) {
    return 'branches';
}

if ($this->isInstallmentTotalIntent($m)) {
    return 'installment_total';
}

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

    /**
     * العميل بيعترض على الحسبة نفسها (بيحسب بنفسه أو بيقول الرقم غلط).
     *
     * بيرجّع الشرح الصريح لفرق سعر الكاش وسعر التقسيط، أو null لو
     * الرسالة مش اعتراض - وساعتها الحسبة بتكمل عادي.
     *
     * @param  \Illuminate\Support\Collection<int, Machine>  $machines
     */
    private function priceObjectionReply(WhatsappConversation $conversation, Collection $machines, string $message): ?string
    {
        if ($machines->isEmpty()) {
            return null;
        }

        $text = $this->normalizeText($message);

        /*
         * طلب حسبة ≠ اعتراض على حسبة. الشرط ده اتكسر في الإنتاج:
         * containsAny() بتعمل normalize للكلمة المفتاحية كمان، فـ"الحسبه"
         * بقت "حسبه" - و"احسبهالي ع سنتين الوينج ٢٠٠" جواها "حسبه"!
         * فطلب حسبة عادي رجعله رد اعتراض على السعر بدل القسط.
         *
         * فأي رسالة فيها طلب حسبة صريح مبتتحسبش اعتراض أبدًا.
         */
        if ($this->containsAny($text, ['احسب', 'اخسب', 'حسبهالي', 'حسبها لي', 'عايز اقسط', 'عاوز اقسط', 'قسطهالي'])) {
            return null;
        }

        /*
         * الاعتراض لازم يبان صريح: العميل بيقول إن الرقم غلط أو بيسأل
         * "ليه". الكلمات دي متقاسة بعد normalize، فمكتوبة من غير "ال".
         */
        $questionsTheNumber = $this->containsAny($text, [
            'ليه كده', 'ليه الرقم', 'ليه القسط', 'مش مظبوط', 'مش مضبوط',
            'حسابكم غلط', 'حسبتكم غلط', 'رقم غلط', 'مفروض يكون', 'مفروض يطلع',
            'انت قلت', 'مش كده', 'ازاي بقي',
        ]);

        // لازم يكون معاه أرقام - ده اللي بيفرّق الاعتراض عن السؤال العام.
        $hasNumbers = preg_match_all('/\d{3,}/u', $this->normalizeDigitsForCompare($message)) >= 1;

        // ولازم نكون فعلاً قلنا له قسط قبل كده - من غير كده مفيش حاجة يعترض عليها.
        $quotedBefore = ($conversation->last_topic ?? null) === 'installment_calc'
            || ! empty(($conversation->context_payload ?? [])['last_months']);

        if (! $questionsTheNumber || ! $hasNumbers || ! $quotedBefore) {
            return null;
        }

        $machine = $machines->first();
        $cash = (float) ($machine->cash_price ?? 0);
        $installmentBase = (float) ($machine->installment_price ?? 0);

        if ($cash <= 0 || $installmentBase <= 0 || $installmentBase <= $cash) {
            return null;
        }

        /*
         * ميموري «التسعير وطريقة عرض التقسيط»: ممنوع نقول للعميل سعر
         * التقسيط الأساسي ولا نعرّفه إنه أعلى من الكاش - الرد المسموح هو
         * إن ده نظام تمويل وفيه تكلفة تمويل، من غير أي تفاصيل داخلية.
         *
         * فالمطلوب هنا إننا **نرد على اعتراضه** بدل ما نعيد نفس بلوك
         * القسط (اللي حصل في محادثة 52)، وفي نفس الوقت منكسرش القاعدة
         * دي: بنقر إن حسبته على سعر الكاش، ونقول إن القسط بيتحسب بنظام
         * تمويل - من غير ما نذكر رقم سعر التقسيط.
         */
        return 'حسبة حضرتك صح لو كنا بنقسّط على سعر الكاش، بس القسط بيتحسب بنظام تمويل '
            . "وفيه تكلفة تمويل وتشغيل - عشان كده الرقم بيطلع أعلى شوية من اللي حسبته.\n"
            . 'القسط اللي قلتهولك هو الرقم النهائي اللي هتدفعه شهريًا، ومفيش أي مبالغ مخفية بعده. '
            . 'تحب أحسبهالك على مدة أطول عشان القسط ينزل؟';
    }

    /** أرقام عربية/هندية -> إنجليزية، عشان المقارنة تشوف الرقم زي ما هو. */
    private function normalizeDigitsForCompare(string $text): string
    {
        return str_replace(
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '،', ','],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '', ''],
            $text
        );
    }

    /**
     * شتيمة أو إهانة صريحة في رسالة العميل.
     *
     * الليستة مقصود تكون للألفاظ اللي مالهاش استخدام تاني في سياق البيع،
     * عشان "زفت" في "الجو زفت" ما تتحسبش إهانة للبوت. المقارنة بتحصل
     * على النص بعد التوحيد (بتشيل التشكيل وبتوحّد الألف والياء).
     */
    private function messageIsAbusive(string $message): bool
    {
        $text = $this->normalizeText($message);

        $insults = [
            'يعرص', 'عرص', 'خول', 'متناك', 'كس ام', 'كسم', 'كسمك', 'ابن الوسخه',
            'ابن المتناكه', 'شرموط', 'شرموطه', 'قحبه', 'زبي', 'طيز', 'يا حيوان',
            'يا حمار', 'يا كلب', 'ابن الكلب', 'انت هلس', 'يهلس', 'يبايظ', 'اتنيل',
            'الرقم القومي عند امك', 'عند امك', 'في امك',
        ];

        foreach ($insults as $insult) {
            if (str_contains($text, $insult)) {
                return true;
            }
        }

        return false;
    }

    /**
     * الرد الإضافي بيقول نفس اللي الرد الأساسي قاله؟
     *
     * المقارنة بالأرقام: لو كل الأرقام اللي في الخطوة الإضافية موجودة
     * أصلاً في الرد الأساسي، يبقى مفيش معلومة جديدة فيها.
     */
    private function replySaysTheSameThing(string $primary, string $extra): bool
    {
        $primary = trim($primary);

        if ($primary === '' || $extra === '') {
            return false;
        }

        preg_match_all('/\d[\d,]{2,}/u', $extra, $extraNumbers);

        $extraNumbers = array_values(array_unique($extraNumbers[0] ?? []));

        if ($extraNumbers === []) {
            return false;
        }

        foreach ($extraNumbers as $number) {
            if (! str_contains($primary, $number)) {
                return false;
            }
        }

        return true;
    }

    /**
     * المكن اللي اتذكر في الرسالة لما نقرا كل جزء فيها لوحده.
     *
     * "دايون 4 و وينج 200" -> [دايو ٤، وينج ٢٠٠]. البحث على الرسالة
     * كاملة بيرجّع واحد بس، فالعميل بيسأل على اتنين وياخد رد على واحد.
     *
     * @return Collection<int, Machine>
     */
    private function machinesFromEachSegment(string $message): Collection
    {
        $segments = preg_split('/\s+و\s+|\s*[،,+]\s*|\s+&\s+/u', trim($message), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($segments) < 2) {
            return collect();
        }

        $search = app(MachineSearchService::class);
        $found = collect();

        foreach ($segments as $segment) {
            $segment = trim($segment);

            // جزء قصير جدًا ("و"، "ب") مالوش لازمة ومش هيطابق موديل.
            if (mb_strlen($segment) < 3) {
                continue;
            }

            /*
             * أقرب مكنة لكل جزء بس - العميل ذكر موديلين، فالرد يبقى على
             * الاتنين مش على مطابقات تقريبية زيادة.
             */
            foreach ($search->search($segment, 1) as $machine) {
                if (! $found->contains(fn (Machine $m) => $m->id === $machine->id)) {
                    $found->push($machine);
                }
            }
        }

        return $found;
    }

    /**
     * فيه مكنة متحددة في المحادثة نقدّم عليها؟
     *
     * @param  \Illuminate\Support\Collection<int, Machine>  $lastMachines
     */
    private function hasChosenMachine(WhatsappConversation $conversation, $lastMachines): bool
    {
        if ($lastMachines instanceof Collection && $lastMachines->isNotEmpty()) {
            return true;
        }

        if (! empty($conversation->last_machine_ids)) {
            return true;
        }

        $payload = $conversation->context_payload ?? [];

        return ! empty($payload['application']['machine_id'])
            || ! empty($payload['last_calc_machine_ids']);
    }

    /**
     * العميل بيسأل على نوع مكنة إحنا مش بنبيعه أصلاً؟
     *
     * بيتقري من الكتالوج الحقيقي مش من ليستة ثابتة: لو الكلمة اللي
     * العميل قالها (كهربائي مثلاً) مفيش ليها ولا مكنة واحدة في
     * الداتابيز، الرد الصح "مفيش عندنا" + البديل - مش "تقصد أنهي موديل؟"
     * اللي بتلف بيه في دايرة.
     */
    private function unavailableCategoryReply(string $message): ?string
    {
        $text = $this->normalizeText($message);

        $categories = [
            'كهرب' => 'موتوسيكلات كهربائية',
            'electric' => 'موتوسيكلات كهربائية',
            'سكوتر كهرب' => 'سكوترات كهربائية',
            'دراجه كهرب' => 'دراجات كهربائية',
        ];

        foreach ($categories as $needle => $label) {
            if (! str_contains($text, $needle)) {
                continue;
            }

            $exists = Machine::query()
                ->where(function ($q) use ($needle) {
                    $q->where('name', 'like', "%{$needle}%")
                        ->orWhere('aliases', 'like', "%{$needle}%");
                })
                ->exists();

            if ($exists) {
                return null;
            }

            $available = Machine::query()->inRandomOrder()->limit(3)->pluck('name')->implode('، ');

            return "معلش يا فندم، إحنا معندناش {$label} خالص - كل اللي عندنا بنزين."
                . ($available !== '' ? " من اللي متوفر عندنا: {$available}." : '')
                . ' تحب أرشحلك موديل يناسب استخدامك؟';
        }

        return null;
    }

    /**
     * رسالة مالهاش محتوى جديد وجاية ورا رد بعتناه للتو.
     *
     * نوعين:
     *  - علامات ترقيم بس (. .. ؟؟ ...) = استعجال، مبيتردش عليها.
     *  - شكر/تأكيد قصير بعد ما شكرناه خلاص = رد تاني عليه بيبقى تكرار.
     */
    private function isFillerFollowUp(WhatsappConversation $conversation, string $message): bool
    {
        $text = trim($message);

        $lastOutgoing = WhatsappMessage::query()
            ->where('whatsapp_conversation_id', $conversation->id)
            ->where('direction', 'outgoing')
            ->latest('id')
            ->first();

        if (! $lastOutgoing) {
            return false;
        }

        $secondsSinceReply = now()->diffInSeconds($lastOutgoing->created_at, true);

        // علامات ترقيم أو حروف مد بس - مفيش فيها أي طلب.
        if (preg_match('/^[\s\.\?؟!،,ـ_\-]+$/u', $text) === 1) {
            return $secondsSinceReply <= 600;
        }

        $normalized = $this->normalizeText($text);

        $isThanks = in_array($normalized, [
            'شكرا', 'شكرا جدا', 'متشكر', 'متشكر جدا', 'تسلم', 'تسلمي', 'تسلم ايدك',
            'ربنا يكرمك', 'جزاك الله خير', 'تمام', 'تمام شكرا', 'ماشي', 'اوك', 'ok', 'تمام تسلم',
        ], true);

        if (! $isThanks || $secondsSinceReply > 300) {
            return false;
        }

        /*
         * أول شكر بياخد رد. اللي بعده في نفس اللحظة (العميل بيبعت "تمام"
         * وبعدها "شكرا") مش محتاج رسالة تانية بنفس الكلام.
         */
        $repliedToThanks = str_contains((string) $lastOutgoing->message, 'العفو')
            || str_contains((string) $lastOutgoing->message, 'تحت أمرك');

        return $repliedToThanks;
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

    /**
     * جُمل معناها "آخر رسالة منك مكانتش واضحة" - مش "عايز أكلم موظف".
     */
    private function isConfusionMessage(string $message): bool
    {
        $m = $this->normalizeText($message);

        return $this->containsAny($m, [
            'مش فاهم حاجه',
            'مش فاهمه حاجه',
            'مش فاهم قصدك',
            'مش فاهمه قصدك',
            'مش فاهم انت بتقول ايه',
            'انا مش فاهم',
            'انا مش فاهمه',
            'وضحلي',
            'وضحلى',
            'مش واضح',
            'اشرحلي تاني',
            'اشرحلى تاني',
            'يعني ايه كده',
            'معرفتش اقصد ايه',
        ]);
    }

    /**
     * بيعيد شرح آخر رسالة خرجت منّا بصياغة أبسط. بيرجّع null لو مفيش
     * رسالة سابقة نشرحها (ساعتها الرسالة تكمل مسارها الطبيعي).
     */
    private function handleConfusionMessage(WhatsappConversation $conversation, string $message): ?array
    {
        $previous = trim((string) $conversation->messages()
            ->where('direction', 'outgoing')
            ->whereNotIn('payload->source', ['human_handoff', 'handoff_waiting_ack'])
            ->latest('id')
            ->value('message'));

        if ($previous === '') {
            return null;
        }

        $prompt = <<<TXT
        إنت موظف خدمة عملاء مصري في معرض موتوسيكلات، بتتكلم مصري عامي بسيط.

        العميل قال إنه مش فاهم آخر رسالة بعتناها له. دي الرسالة:
        ---
        {$previous}
        ---

        وده اللي كتبه العميل: "{$message}"

        اشرحله نفس المعلومة تاني بكلام أبسط بكتير، في سطرين بحد أقصى.

        قواعد إلزامية:
        - ممنوع تضيف أي رقم أو معلومة مش موجودة في الرسالة فوق، وممنوع
          تشيل أي رقم منها.
        - ممنوع تعتذر بكلام كتير - جملة قصيرة تكفي.
        - لو الرسالة كانت سؤال، سيبها سؤال، وحط الاختيارات صريحة.
        - رد بنص الرسالة الجديدة بس.
        TXT;

        try {
            $result = app(GeminiClient::class)->generateText(
                prompt: $prompt,
                preferredModelCode: config('gemini.models.fast'),
                options: [
                    'timeout' => 12,
                    'temperature' => 0.6,
                    'thinkingBudget' => 0,
                    'maxOutputTokens' => 400,
                ]
            );

            $reply = trim((string) ($result['reply'] ?? ''));

            if (($result['ok'] ?? false) && $reply !== '') {
                app(ClarificationService::class)->reset($conversation);

                return $this->textReply($conversation, trim($reply, "\"' \n\t"));
            }
        } catch (\Throwable $e) {
            Log::warning('confusion rephrase failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
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
            /*
             * "مش فاهم حاجة" كانت هنا وكانت بتشيل العميل من المحادثة على
             * طول وتحطه في انتظار موظف - وهو أصلًا بيقول إنه محتاج شرح
             * أبسط، مش إنه عايز حد تاني. اتشالت عمدًا: دلوقتي بتتعامل
             * كطلب تبسيط في handleConfusionMessage().
             */
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

        $payload = $conversation->context_payload ?? [];
        $lastCalcMachineIds = collect($payload['last_calc_machine_ids'] ?? [])->all();
        $hasMatchingCalc = in_array($machine->id, $lastCalcMachineIds, true)
            && array_key_exists('last_months', $payload);

        $calc = null;

        if ($hasMatchingCalc) {
            $months = (int) $payload['last_months'];
            $deposit = (float) ($payload['last_deposit'] ?? 0);
            $system = (string) ($payload['last_system'] ?? '20');

            /*
             * Was missing $isFreelance - handleInstallmentCalc() and
             * installmentSnapshot() both pass it, so a freelance customer
             * above the 60,000 finance cap got a DIFFERENT admin fee here
             * than the one already quoted for the same machine (see
             * AI_WHATSAPP_BOT_MEMORY_INTELLIGENCE_AUDIT.md §17 A-4).
             */
            $isFreelance = app(ApplicationHandler::class)->categorizeIncome(
                (string) ($payload['application']['job_type'] ?? ''),
                (string) ($payload['application']['income_proof'] ?? '')
            ) === 'freelance';

            $candidate = app(InstallmentCalculator::class)->calculate($machine, $months, $deposit, $system, $isFreelance);

            if ($candidate['ok'] ?? false) {
                $calc = $candidate;
            }
        }

        /*
         * المصاريف الإدارية 7% من المبلغ المموَّل - يعني *بعد* المقدم، مش
         * من سعر التقسيط الكامل. لما يكون فيه حسبة شغالة بمقدم، الرقم
         * الصح هو اللي طالع من InstallmentCalculator؛ الرقم المحسوب من
         * السعر الكامل كان بيتكتب في أول سطر والرقم الصح في السطر اللي
         * بعده، فالعميل يشوف رقمين مختلفين لنفس البند في نفس الرسالة.
         */
        $adminFee = $calc !== null
            ? (int) $calc['admin_fee']
            : (int) round($installmentPrice * 0.07);

        $basisText = ($calc !== null && (float) $calc['deposit'] > 0)
            ? '7% من المبلغ الباقي بعد المقدم'
            : '7% من تمنها في التقسيط';

        $lines = [
            "المصاريف الإدارية لـ {$displayName} بتكون " . number_format($adminFee)
                . " جنيه ({$basisText})، بتتدفع وانت بتستلم المكنة، ودي رسوم شركة التمويل بتحطها مش من المعرض.",
        ];

        if ($calc !== null) {
            $monthly = (int) $calc['monthly_payment'];
            $deposit = (float) $calc['deposit'];
            $freelanceExtra = (float) ($calc['freelance_extra_deposit'] ?? 0);
            $totalAtPickup = $adminFee + $deposit + $freelanceExtra;

            $depositLine = $deposit > 0 ? ' + المقدم ' . number_format($deposit) . ' جنيه' : '';
            $freelanceLine = $freelanceExtra > 0 ? ' + مقدم إضافي (سقف الدخل الحر) ' . number_format($freelanceExtra) . ' جنيه' : '';

            $lines[] = 'وقت استلام المكنة هتدفع: المصاريف الإدارية ' . number_format($adminFee) . ' جنيه'
                . $depositLine . $freelanceLine
                . ' = إجمالي ' . number_format($totalAtPickup) . ' جنيه.'
                . "\nوبعد كده بـ 45 يوم من الاستلام، أول قسط شهري يكون " . number_format($monthly) . ' جنيه.';
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

        foreach (['machine_id', 'machine_name', 'payment_method', 'installment_months'] as $key) {
            unset($application[$key]);
        }

        $payload['application'] = $application;
        $payload['missing_fields'] = [];
        unset(
            $payload['pending_conflicts'],
            $payload['no_progress_streak'],
            $payload['documents_required'],
            $payload['documents_index'],
            $payload['documents_collected'],
            /*
             * These fed ApplicationHandler's "customer already calculated a
             * term" auto-fill and installmentSnapshot() - left in place, a
             * restart on a new machine silently inherited the OLD machine's
             * term/deposit/system (AI_WHATSAPP_BOT_MEMORY_INTELLIGENCE_AUDIT.md
             * §18, "term bleeds across machines").
             */
            $payload['last_months'],
            $payload['last_deposit'],
            $payload['last_system'],
            $payload['last_calc_machine_ids'],
            $payload['installment_repeat_streak']
        );

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
     * قبل كده المحادثة المحوّلة لموظف كانت ثقب أسود: البوت بيرجّع null
     * على أي رسالة، والعميل بيبعت "في حد؟" و"ردوا عليا" ومحدش بيرد
     * خالص - حتى على سؤال سعر البوت يعرف إجابته في ثانية. ده أسوأ من
     * إنه ميحولش أصلًا.
     *
     * دلوقتي بنعمل حاجتين:
     *  ١) لو عدّى وقت طويل من غير ما موظف يرد فعلًا، بنفتح المحادثة تاني
     *     ونكمل عادي بدل ما العميل يفضل مستني للأبد.
     *  ٢) طول ما احنا مستنيين، بنبعت طمأنة قصيرة *مرة واحدة كل فترة*
     *     (مش على كل رسالة) عشان العميل يعرف إن رسايله واصلة.
     *
     * الـ AI لسه مش بيرد ردود موضوعية طول ما الموظف ماسك المحادثة - ده
     * كان قرار مقصود عشان ميتعارضش مع الموظف - بس السكوت التام اتشال.
     */
    private function handleWhileAwaitingAgent(
        WhatsappConversation $conversation,
        string $message,
        array $mediaItems = []
    ): array {
        $handoffAt = $conversation->messages()
            ->where('direction', 'outgoing')
            ->where('payload->source', 'human_handoff')
            ->latest('id')
            ->value('created_at');

        $agentReplied = $handoffAt
            ? $conversation->messages()
                ->where('direction', 'outgoing')
                ->where('payload->source', 'agent_dashboard_reply')
                ->where('created_at', '>=', $handoffAt)
                ->exists()
            : false;

        $reopenAfter = (int) config('whatsapp.handoff_auto_reopen_minutes', 180);

        if (
            ! $agentReplied
            && $reopenAfter > 0
            && $handoffAt
            && $handoffAt->diffInMinutes(now()) >= $reopenAfter
        ) {
            $conversation->forceFill([
                'status' => 'open',
                'clarification_attempts' => 0,
                'last_clarification_question' => null,
            ])->save();

            Log::info('ai_handoff_auto_reopen', [
                'conversation_id' => $conversation->id,
                'minutes_waiting' => $handoffAt->diffInMinutes(now()),
            ]);

            return $this->handleInternal($conversation->refresh(), $message, $mediaItems);
        }

        $lastAck = $conversation->messages()
            ->where('direction', 'outgoing')
            ->where('payload->source', 'handoff_waiting_ack')
            ->latest('id')
            ->value('created_at');

        $ackEvery = (int) config('whatsapp.handoff_ack_every_minutes', 60);

        if ($lastAck && $lastAck->diffInMinutes(now()) < $ackEvery) {
            return $this->textResult(null);
        }

        $reply = $this->renderMemory('رد انتظار الموظف')
            ?: 'رسالتك وصلت يا فندم، وزميلي هيكلمك من هنا في أقرب وقت. لو الموضوع مستعجل قولي وأنا أعلّم عليه.';

        $this->saveOutgoing($conversation, $reply, ['source' => 'handoff_waiting_ack']);

        return $this->textResult($reply);
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
    /**
     * Does this message read as a question rather than an answer? Used to
     * decide whether a general/unknown message arriving mid-application is
     * something to answer or data to extract.
     */
    private function looksLikeQuestion(string $message): bool
    {
        $trimmed = trim($message);

        if ($trimmed === '') {
            return false;
        }

        if (str_contains($trimmed, '؟') || str_contains($trimmed, '?')) {
            return true;
        }

        $normalized = $this->normalizeText($trimmed);

        return preg_match(
            '/(^|\s)(ليه|ازاي|امتى|فين|هل|ينفع|يعني ايه|ايه ال|ايه هي|ايه هو|مين|كام|بكام|ممكن|لو سمحت اعرف|عايز اعرف|عاوز اعرف|محتاج اعرف|ولو|طب لو|ماذا|متى)(\s|$)/u',
            $normalized
        ) === 1;
    }

    /**
     * Guards looksLikeQuestion() against treating an actual answer as a
     * question: a national ID, a phone number, a bare name, or an address
     * line can contain a word like "فين" or "كام" without being a question.
     */
    private function looksLikeApplicationData(string $message): bool
    {
        $trimmed = trim($message);

        if ($trimmed === '') {
            return false;
        }

        // Mostly digits (national ID / phone / months) - always data.
        $digits = preg_replace('/\D+/u', '', $trimmed) ?? '';

        if (mb_strlen($digits) >= 8) {
            return true;
        }

        // Address-shaped: mentions street/building/floor/flat vocabulary.
        $normalized = $this->normalizeText($trimmed);

        return preg_match('/(شارع|ش |متفرع|عماره|عمارة|الدور|شقه|شقة|محافظه|محافظة|منطقه|منطقة|بجوار|امام|خلف)/u', $normalized) === 1;
    }

    /**
     * Compact, model-facing snapshot of the in-progress application, or
     * null when there is none. Feeds AiPromptBuilder::formatApplicationState
     * so the free-reply model stops being blind to application state (see
     * AI_WHATSAPP_BOT_MEMORY_INTELLIGENCE_AUDIT.md §2/§3/§6.2) - it can
     * answer a side question mid-application without re-asking for a field
     * already collected.
     */
    private function applicationStateForPrompt(WhatsappConversation $conversation): ?array
    {
        $pendingQuestion = $conversation->pending_question ?? null;

        if (! in_array($pendingQuestion, ['application_missing_data', 'application_documents'], true)) {
            return null;
        }

        try {
            $payload = $conversation->context_payload ?? [];
            $application = $payload['application'] ?? [];

            if (empty($application)) {
                return null;
            }

            $stateService = app(\App\Services\ApplicationStateService::class);
            $application = $stateService->refreshAddressComponents($application);

            $incomeCategory = app(ApplicationHandler::class)->categorizeIncome(
                (string) ($application['job_type'] ?? ''),
                (string) ($application['income_proof'] ?? '')
            );
            $isFreelance = $incomeCategory === 'freelance';
            $requiresVehicle = app(ApplicationHandler::class)->requiresVehicleAnswer($incomeCategory);

            $missing = $stateService->missingFields($application, $isFreelance, $requiresVehicle);

            $labels = [
                'full_name' => 'الاسم بالكامل',
                'national_id' => 'الرقم القومي',
                'phone' => 'رقم الموبايل',
                'job_type' => 'طبيعة شغلك',
                'income_proof' => 'إثبات الدخل',
                'work_address' => 'عنوان الشغل',
                'home_address' => 'عنوان السكن',
                'installment_months' => 'مدة التقسيط',
                'work_vehicle' => 'نوع المركبة',
            ];

            $known = [];

            foreach ($labels as $field => $label) {
                if (! empty($application[$field]) && ! in_array($field, $missing, true)) {
                    $known[$label] = is_string($application[$field]) ? mb_substr($application[$field], 0, 60) : $application[$field];
                }
            }

            return [
                'pending_question' => $pendingQuestion === 'application_documents'
                    ? 'جمع المستندات - البيانات الأساسية اتجمعت'
                    : 'جمع بيانات التقديم',
                'known' => $known,
                'missing' => array_map(fn ($field) => $labels[$field] ?? $field, $missing),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

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

            $incomeCategory = app(ApplicationHandler::class)->categorizeIncome(
                (string) ($application['job_type'] ?? ''),
                (string) ($application['income_proof'] ?? '')
            );
            $isFreelance = $incomeCategory === 'freelance';

            /*
             * Was missing $requiresVehicle - a delivery/taxi customer with
             * only work_vehicle left could get "result already complete"
             * here while ApplicationHandler still blocks on it, so the
             * resume line silently dropped the one field actually needed.
             */
            $requiresVehicle = app(ApplicationHandler::class)->requiresVehicleAnswer($incomeCategory);
            $missing = $stateService->missingFields($application, $isFreelance, $requiresVehicle);

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
                'work_vehicle' => 'نوع المركبة',
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

    /*
     * الموديلات المحفوظة بتبقى صالحة لسياق الجلسة الحالية بس. من غير
     * الحد ده، عميل سأل عن بينيلي إمبارح كان بيلاقي البوت بيرشّحله
     * VLR 150 بسعرها النهارده رد على رسالة مالهاش أي علاقة، لأن
     * last_machine_ids كانت بتتحقن في البرومبت مع الأسعار للأبد.
     */
    $staleAfterMinutes = (int) config('whatsapp.last_machines_ttl_minutes', 180);
    $lastActivity = $conversation->updated_at;

    if ($lastActivity && $lastActivity->diffInMinutes(now()) > $staleAfterMinutes) {
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

/**
 * بيدوّر على أسماء موديلات في نص رسالة العميل الخام، ويرجّعها بس لو
 * مفيش ولا واحد منهم في مجموعة الموديلات اللي المحادثة واقفة عليها.
 *
 * بيرجّع null معناها "مفيش دليل إن ده موضوع جديد" - سواء الرسالة مفيهاش
 * اسم موديل خالص (زي "سعرها كام")، أو الأسماء اللي فيها جوه المجموعة
 * القديمة أصلاً (تضييق حقيقي). في الحالتين الفرع اللي بعده بيكمّل عادي.
 */
private function machinesNamedOutsideLastSet(
    WhatsappConversation $conversation,
    string $message
): ?Collection {
    $message = trim($message);

    if ($message === '') {
        return null;
    }

    $last = $this->lastMachinesFromConversation($conversation);

    if ($last->isEmpty()) {
        return null;
    }

    $found = app(MachineSearchService::class)->search($message, 20);

    if ($found->isEmpty()) {
        return null;
    }

    $lastIds = $last->pluck('id')->map(fn ($id) => (int) $id)->all();

    $overlaps = $found->contains(fn (Machine $machine) => in_array((int) $machine->id, $lastIds, true));

    if ($overlaps) {
        return null;
    }

    Log::info('new_machine_named_outside_last_set', [
        'conversation_id' => $conversation->id,
        'message' => mb_substr($message, 0, 120),
        'resolved' => $found->pluck('name')->all(),
        'last_set' => $last->pluck('name')->all(),
    ]);

    return $found;
}

private function resolveMachinesFromPlan(
    WhatsappConversation $conversation,
    string $message,
    array $plan
): Collection {
    $target = $plan['target'] ?? 'unknown';
    $hasMachineQuery = ! empty($plan['machine_query']);

    /*
     * العميل سمّى موديل تاني بالاسم - ده طلب جديد، مش تضييق على اللي فات.
     *
     * الحالة اللي كشفت ده: البوت عرض "دايو 2" و"دايو 2 استيراد"، والعميل
     * سأل بعدها "سعر دايونج كام". الـ planner رجّع
     * target=previous_selection وmachine_query="دايو" (قصّ الاسم!)، فالرد
     * رجّع نفس موديلات دايو 2 تاني - و"دايونج" (موديل حقيقي id=56 سعره
     * 45,000) مرجعش خالص.
     *
     * السبب إن فرع previous_selection تحت كان بيرجّع الموديلات القديمة
     * على طول من غير ما يبص على نص رسالة العميل أصلاً. فأي غلطة في
     * الـ planner مكانش قدامها أي حاجة توقفها.
     *
     * الفحص ده حتمي ومستقل عن الـ planner: بندوّر في نص الرسالة الخام،
     * ولو طلّعت موديلات **مفيش ولا واحد فيهم في المجموعة القديمة**، يبقى
     * دي رسالة عن حاجة تانية خالص.
     *
     * الشرط "ولا واحد" مقصود بالظبط: "دايو 2 استيراد" بعد عرض العيلة
     * بترجّع موديل جوه المجموعة، فتفضل تضييق زي ما هي؛ و"سعرها كام"
     * مبترجّعش أي موديل، فمبتلمسش الفرع ده.
     */
    if (in_array($target, ['previous_selection', 'single_previous_machine', 'selected_index'], true)) {
        $named = $this->machinesNamedOutsideLastSet($conversation, $message);

        if ($named !== null) {
            return $named;
        }
    }

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

    /*
     * الرد ده بلوك جاهز فيه كل حاجة عن التقسيط - المؤهلين والسن
     * والنظامين والمصاريف والمستندات. وكان بيخرج كامل على أي سؤال
     * يتصنّف installment_system، حتى لو العميل سأل سؤال واحد محدد.
     * فـ"هي الفوايد كام؟" كان بيرجعله ١٢ سطر مفيهمش رقم الفايدة، و"يعني
     * ايه؟" بيرجعله نفس البلوك بالحرف. العميل بيتوه وميلاقيش إجابته.
     *
     * دلوقتي بنجاوب من نفس البلوك بس على السؤال اللي اتسأل. البلوك
     * الكامل لسه بيخرج زي ما هو لما السؤال يكون عام فعلًا، أو لو
     * التركيز فشل - فأسوأ حالة هي اللي كانت شغالة قبل كده.
     */
    $focused = $this->focusedAnswerFrom($reply, $message, $conversation);

    if ($focused !== null) {
        $reply = $focused;
    } elseif ($this->systemBlockAlreadySent($conversation)) {
        /*
         * البلوك ده اتبعت خلاص في المحادثة دي. إعادته على سؤال محدد
         * تاني هي اللي خلت عميل في محادثة الإعلان ياخده أربع مرات
         * ("فايده كام على السنة"، "قسط مباشر ولا أبلكيشن"، "مطلوب إيه
         * أجيبه معايا") من غير ما يلاقي إجابة سؤاله ولا مرة.
         *
         * لو مقدرناش نطلّع إجابة مركّزة، الصح إننا نقول إننا هنتأكد -
         * مش نبعت نفس اتناشر سطر تاني.
         */
        \Illuminate\Support\Facades\Log::info('ai_missing_policy_answer', [
            'conversation_id' => $conversation->id,
            'question' => mb_substr($message, 0, 200),
            'missing' => 'repeat_of_installment_block',
        ]);

        $reply = 'سؤال حضرتك ده محتاج إجابة محددة مش موجودة في اللي بعتهولك فوق. '
            . 'هتأكد من زميلي وأرد على حضرتك حالًا - ولو تحب أوصلك بيه على طول قولي "عايز أكلم حد".';
    } else {
        $this->markSystemBlockSent($conversation);
    }

    $this->saveOutgoing($conversation, $reply, [
        'source' => 'installment_system',
        'message' => $message,
        'focused' => $focused !== null,
    ]);

$this->updateConversationState($conversation, 'installment_system', null, [
    'machine_ids' => $conversation->last_machine_ids ?? [],
]);
    return $this->textResult($reply);
}


/**
 * بياخد بلوك معلومات جاهز وسؤال العميل، ويرجّع الجزء اللي بيجاوب على
 * السؤال ده بس. بيرجّع null لو السؤال عام (يعني البلوك كله هو الإجابة
 * الصح)، أو لو الرد المختصر مش موثوق.
 *
 * الأمان هنا مهم: الرد الجديد ممنوع يحتوي أي رقم مش موجود في البلوك
 * الأصلي - نفس ضمانة AiReplyPhraser بالظبط، عشان اختصار الرسالة عمره
 * ما يتحوّل لاختراع شرط أو نسبة.
 */
/**
 * هل بلوك نظام التقسيط الكامل اتبعت قبل كده في المحادثة دي؟
 *
 * البلوك ده إجابة سؤال عام واحد؛ تكراره على أسئلة محددة بيغرق العميل
 * في نفس النص من غير ما يلاقي إجابته.
 */
private function systemBlockAlreadySent(WhatsappConversation $conversation): bool
{
    $payload = $conversation->context_payload ?? [];

    return ! empty($payload['installment_block_sent']);
}

private function markSystemBlockSent(WhatsappConversation $conversation): void
{
    $payload = $conversation->context_payload ?? [];
    $payload['installment_block_sent'] = true;

    $conversation->forceFill(['context_payload' => $payload])->save();
}

private function focusedAnswerFrom(string $block, string $question, ?WhatsappConversation $conversation = null): ?string
{
    $question = trim($question);

    if ($question === '' || mb_strlen($block) < 200) {
        return null;
    }

    $prompt = <<<TXT
    إنت موظف خدمة عملاء مصري في معرض موتوسيكلات.

    دي كل المعلومات المعتمدة عن التقسيط:
    ---
    {$block}
    ---

    العميل سأل: "{$question}"

    رجّع JSON بالشكل ده بس:
    {"answer": "..." أو null, "unanswered": "..." أو null}

    - answer: لو السؤال بيسأل عن **جزء محدد** من المعلومات اللي فوق
      (نسبة، فايدة، مصاريف، مدد، مين ينفع يقسط، السن، المستندات، مواعيد
      السداد)، اكتب الإجابة على السؤال ده بس في سطرين بحد أقصى.
      خلي answer = null **بس** لو العميل طالب النظام كله من الأول
      (زي "قوللي عن التقسيط" أو "إيه أنظمتكم").
    - أي سؤال بصيغة سؤال محدد لازم يطلع في answer أو في unanswered -
      ممنوع يطلع الاتنين null.
    - unanswered: لو العميل سأل سؤال محدد **إجابته مش موجودة خالص** في
      المعلومات فوق (زي "ينفع من غير مقدم؟" أو "معنديش رخصة ينفع؟")،
      اكتب السؤال ده في جملة قصيرة جدًا بصيغة "من غير مقدم" أو "من غير
      رخصة". أكتر من سؤال؟ اكتبهم مفصولين بـ"و". غير كده خليه null.

    قواعد إلزامية:
    - ممنوع تكتب أي رقم أو نسبة أو شرط مش مكتوب حرفيًا في المعلومات فوق.
    - ممنوع تخمّن إجابة سؤال مش مذكور فوق - مكانه unanswered.
    - مصري عامي، من غير مقدمات.
    TXT;

    try {
        $result = app(GeminiClient::class)->generateText(
            prompt: $prompt,
            preferredModelCode: config('gemini.models.fast'),
            options: [
                'timeout' => 12,
                'temperature' => 0.3,
                'thinkingBudget' => 0,
                'maxOutputTokens' => 400,
                'responseMimeType' => 'application/json',
            ]
        );
    } catch (\Throwable $e) {
        return null;
    }

    if (! ($result['ok'] ?? false)) {
        return null;
    }

    $decoded = json_decode(trim((string) ($result['reply'] ?? '')), true);

    if (! is_array($decoded)) {
        return null;
    }

    $answer = trim((string) ($decoded['answer'] ?? ''));
    $unanswered = trim((string) ($decoded['unanswered'] ?? ''));

    /*
     * العميل سأل حاجة إجابتها مش عندنا في البلوك ("ينفع من غير مقدم؟"،
     * "معنديش رخصة ينفع؟"). قبل كده كان بياخد البلوك كامل وسؤاله بيضيع
     * فيه من غير ما حد يرد عليه أصلًا. بنقر بالسؤال صراحة بدل ما نتجاهله.
     */
    if ($unanswered !== '') {
        /*
         * اللوج ده مقصود يبقى مصدر تغذية للميموري: كل سطر هنا معناه
         * سؤال عميل حقيقي إحنا معندناش إجابته مكتوبة. صاحب المعرض
         * بيضيف القاعدة في ai_memories مرة واحدة، والبوت يبقى بيجاوب
         * عليه لوحده بعد كده من غير أي تعديل كود.
         */
        Log::info('ai_missing_policy_answer', [
            'conversation_id' => $conversation->id ?? null,
            'question' => mb_substr($question, 0, 200),
            'missing' => $unanswered,
        ]);
    }

    $note = $unanswered !== ''
        ? "\n\nوبالنسبة لسؤالك عن {$unanswered} - دي محتاجة أتأكدلك منها وأرد على حضرتك حالًا."
        : '';

    if ($answer === '') {
        return $note !== '' ? $block . $note : null;
    }

    $answer .= $note;

    /*
     * أي رقم في الرد لازم يكون موجود في البلوك الأصلي. رقم جديد معناه
     * إن الموديل اخترع نسبة أو مدة - وده بيوصل للعميل كأنه شرط رسمي.
     */
    if (app(AiReplyPhraser::class)->rejectionReason($answer, $block) !== null) {
        preg_match_all('/\d+/u', $answer, $answerNumbers);
        preg_match_all('/\d+/u', $block, $blockNumbers);

        if (! empty(array_diff($answerNumbers[0] ?? [], $blockNumbers[0] ?? []))) {
            Log::warning('focused_answer_rejected_invented_numbers', [
                'question' => mb_substr($question, 0, 100),
                'answer' => mb_substr($answer, 0, 200),
            ]);

            return null;
        }
    }

    return $answer;
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

/**
 * الفرق بين "احسبلي القسط" و"اخر القسط هكون دافع كام اجمالي": التانية
 * بتطلب مجموع، مش قسط شهري. لازم تبقى فيها كلمة إجمالي/مجموع/في الآخر
 * *ومعاها* إشارة لفلوس أو للمدة، عشان "اجمالي المطلوب عند التعاقد" اللي
 * إحنا نفسنا بنكتبه في ردودنا ما يترجعش علينا كنية غلط.
 */
private function isInstallmentTotalIntent(string $m): bool
{
    $totalWords = $this->containsAny($m, [
        'اجمالي', 'إجمالي', 'الاجمالي', 'مجموع', 'المجموع',
        'كل اللي هدفعه', 'كل الي هدفعه', 'كام هدفع في الاخر',
        'هدفع كام اجمالي', 'هيطلع كام في الاخر', 'يطلعلي بكام',
        'المبلغ الكلي', 'المبلغ الاجمالي', 'التكلفه الكليه', 'التكلفة الكلية',
    ]);

    if (! $totalWords) {
        return false;
    }

    return $this->containsAny($m, [
        'اخر المده', 'اخر المدة', 'في الاخر', 'اخر قسط', 'اخر القسط',
        'المده كلها', 'المدة كلها', 'على المده', 'علي المده',
        'هدفع', 'هدفعه', 'دافع', 'ادفع', 'القسط', 'الاقساط', 'اقساط',
        'شهر', 'سنه', 'سنتين',
    ]);
}

private function isBranchesIntent(string $m): bool
{
    return $this->containsAny($m, [
        'مكانكم', 'مكانكو', 'انتم فين', 'انتو فين', 'المعرض فين',
        'معرضكم فين', 'معرضكو فين', 'فين المعرض', 'فروعكم', 'فروعكو',
        'الفروع', 'عنوانكم', 'عنوانكو', 'عناوينكم', 'العنوان ايه',
        'العنوان فين', 'عندكم فرع', 'اقرب فرع', 'الفرع فين',
        'لوكيشن', 'اللوكيشن', 'لوكشن', 'الموقع', 'موقعكم', 'خريطه',
        'اجيلكم فين', 'اجيلكو فين', 'اجي فين', 'تيجي فين', 'وصل ازاي',
        'اوصلكم ازاي', 'اوصلكو ازاي', 'انتم في اي منطقه', 'في اي منطقه',
    ]) || preg_match('/\b(?:location|address|branch|branches|map)\b/i', $m) === 1;
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

    /*
     * العميل بيراجع حسبتنا بنفسه ("لو 67000 يبقى النسبة 13400 يعني على
     * 18 شهر 4460 تقريبًا ليه 5200؟") - ده اعتراض على رقم، مش طلب حسبة
     * جديدة. الرد القديم كان إعادة نفس بلوك القسط حرفيًا، والعميل يخرج
     * حاسس إن فيه لعب في الأرقام. الإجابة موجودة في الداتا نفسها:
     * القسط محسوب على سعر التقسيط مش سعر الكاش.
     */
    $objection = $this->priceObjectionReply($conversation, $machines, $message);

    if ($objection !== null) {
        return $this->textReply($conversation, $objection);
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

    // ميعاد أول قسط جاي جوه admin_fee_text من InstallmentVariablesBuilder،
    // عشان قوالب ai_memories تاخده هي كمان من غير تعديل يدوي.
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

    /*
     * Plan task 3.5: the machine, the term and the down payment a customer
     * actually asked about are the most useful things we ever learn about
     * them, and they used to die with the conversation row.
     */
    app(CustomerProfileService::class)->rememberInstallmentInterest(
        $conversation,
        $machines->first()?->id,
        (int) $months,
        $deposit
    );

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
 * "طيب اخر القسط هكون دافع كام اجمالي" / "الاجمالي في اخر المده كام".
 *
 * ده كان بيترجم لـ installment_calc، فالعميل كان بياخد **نفس** رسالة
 * القسط الشهري حرفيًا مرة تانية - عمره ما شاف الرقم اللي سأل عليه. كل
 * الأرقام المطلوبة كانت موجودة أصلاً (القسط الشهري × المدة + المقدم +
 * المصاريف الإدارية)، ناقص بس حد يجمعها.
 *
 * المدة/المقدم/النظام بتتقري من الرسالة الحالية الأول (لو العميل غيّرهم
 * في نفس السؤال)، وبعدين من آخر حسبة اتعملت في المحادثة - عشان سؤال
 * متابعة قصير زي "والاجمالي؟" يكمل على نفس السيناريو مش يسأل من الأول.
 */
private function handleInstallmentTotal(
    WhatsappConversation $conversation,
    Collection $machines,
    string $message,
    array $plan = []
): array {
    $parsed = $this->applyAiParsedInstallment(
        app(InstallmentTextParser::class)->parse($message),
        $plan
    );

    if ($machines->isEmpty()) {
        $last = $this->lastMachinesFromConversation($conversation);

        if ($last->isNotEmpty()) {
            $machines = $last;
        }
    }

    if ($machines->isEmpty()) {
        $this->updateConversationState($conversation, 'installment_calc', 'choose_machine');

        return $this->textReply(
            $conversation,
            'تمام يا فندم، ابعتلي اسم المكنة وأحسبلك الإجمالي على المدة كلها.'
        );
    }

    $payload = $conversation->context_payload ?? [];

    $months = $parsed['months'] ?? ($payload['last_months'] ?? null);
    $months = $months !== null ? (int) $months : null;

    if (! $months) {
        $this->rememberMachines($conversation, $machines);
        $this->updateConversationState($conversation, 'installment_calc', 'choose_months', [
            'machine_ids' => $machines->pluck('id')->values()->all(),
        ]);

        return $this->textReply(
            $conversation,
            'تحب أحسبلك الإجمالي على كام شهر يا فندم؟ 12 ولا 18 ولا 24 ولا 36؟'
        );
    }

    $deposit = $parsed['deposit'] !== null && $parsed['deposit'] !== ''
        ? (float) $parsed['deposit']
        : (float) ($payload['last_deposit'] ?? 0);

    $system = $parsed['system'] ?? ($payload['last_system'] ?? '20');
    $system = (string) $system;

    $isFreelance = app(ApplicationHandler::class)->categorizeIncome(
        (string) ($payload['application']['job_type'] ?? ''),
        (string) ($payload['application']['income_proof'] ?? '')
    ) === 'freelance';

    $calculations = collect(
        app(InstallmentCalculator::class)->calculateMany($machines, $months, $deposit, $system, $isFreelance)
    )->filter(fn ($calc) => ($calc['ok'] ?? false) === true)->values();

    if ($calculations->isEmpty()) {
        return $this->textReply(
            $conversation,
            $this->renderMemory('رد عدم توفر سعر التقسيط')
                ?: 'الإجمالي محتاج تأكيد يا فندم لأن سعر التقسيط مش متسجل للموديل ده.'
        );
    }

    $blocks = $calculations->map(function (array $calc) use ($months) {
        $monthly = (int) $calc['monthly_payment'];
        $adminFee = (int) ($calc['admin_fee'] ?? 0);
        $depositDue = (float) ($calc['deposit'] ?? 0) + (float) ($calc['freelance_extra_deposit'] ?? 0);
        $installmentsTotal = $monthly * $months;
        $grandTotal = $installmentsTotal + $adminFee + $depositDue;

        $lines = [
            $calc['machine_name'] . ' على ' . $months . ' شهر:',
            '- الأقساط: ' . number_format($monthly) . ' × ' . $months . ' شهر = ' . number_format($installmentsTotal) . ' جنيه',
        ];

        if ($depositDue > 0) {
            $lines[] = '- المقدم: ' . number_format($depositDue) . ' جنيه';
        }

        if ($adminFee > 0) {
            $lines[] = '- المصاريف الإدارية: ' . number_format($adminFee) . ' جنيه';
        }

        $lines[] = 'الإجمالي من أول ما تستلم لحد آخر قسط: ' . number_format($grandTotal) . ' جنيه';

        return implode("\n", $lines);
    })->implode("\n\n");

    $reply = "تمام يا فندم، ده كل اللي هتدفعه لآخر المدة:\n\n" . $blocks;

    $reply = app(AiReplyPhraser::class)->phrase($reply, [
        'context' => 'إجمالي التقسيط',
        'must_keep' => $machines->map(fn (Machine $machine) => $this->machineDisplayName($machine))->all(),
    ]);

    $this->saveOutgoing($conversation, $reply, [
        'source' => 'installment_total',
        'message' => $message,
        'months' => $months,
        'deposit' => $deposit,
        'system' => $system,
        'calculations' => $calculations->all(),
        'machine_ids' => $machines->pluck('id')->values()->all(),
    ]);

    $this->rememberMachines($conversation, $machines);
    $this->updateConversationState($conversation, 'installment_calc', null, [
        'last_months' => $months,
        'last_system' => $system,
        'last_deposit' => $deposit,
        'last_calc_machine_ids' => $machines->pluck('id')->sort()->values()->all(),
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

        if ($name === '') {
            continue;
        }

        /*
         * str_contains لوحده كان بيطابق جوه الكلمات: "دايونج" (موديل
         * قائم بذاته) فيها "دايو" كـ substring، فأي رسالة فيها "عاوز
         * دايونج" كانت بتتقري كأنها طلب براند دايو كله - والعميل يلاقي
         * قدامه قايمة أسعار كل موديلات دايو بدل الموديل اللي طلبه
         * بالاسم. نفس نوع الباج اللي اتصلح في
         * MachineSearchService::applyWordBoundaryMap.
         */
        if ($this->containsAsWholeWord($m, $this->normalizeText($name))) {
            return [
                'id' => $brand->id,
                'name' => $name,
            ];
        }
    }

    return null;
}

/**
 * str_contains بحدود كلمات. \b مش شغالة صح مع العربي في PCRE، فبنعرّف
 * "حرف الكلمة" يدويًا (عربي أو لاتيني أو رقم) ونتأكد إن اللي قبل
 * وبعد المطابقة مش حرف كلمة.
 */
private function containsAsWholeWord(string $haystack, string $needle): bool
{
    $needle = trim($needle);

    if ($needle === '' || $haystack === '') {
        return false;
    }

    $wordChar = '[\p{Arabic}a-zA-Z0-9]';
    $pattern = '/(?<!' . $wordChar . ')' . preg_quote($needle, '/') . '(?!' . $wordChar . ')/u';

    return (bool) preg_match($pattern, $haystack);
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

/**
 * هل الرسالة تعريف بشغل العميل ومركبته وبس، من غير أي طلب حسبة؟
 *
 * "انا شغال طلبات علي عجله" -> أيوه
 * "انا شغال طلبات، القسط كام؟" -> لأ (فيها طلب حسبة)
 */
private function statesOwnJobOnly(string $normalizedMessage): bool
{
    $statesJob = false;

    foreach (['انا شغال', 'بشتغل', 'شغال في', 'شغال علي', 'شغلي', 'انا سواق', 'انا موظف', 'انا صاحب', 'مهنتي', 'وظيفتي'] as $phrase) {
        if (str_contains($normalizedMessage, $phrase)) {
            $statesJob = true;
            break;
        }
    }

    if (! $statesJob) {
        return false;
    }

    foreach (['قسط', 'تقسيط', 'مقدم', 'سعر', 'بكام', 'كام', 'حساب', 'احسب', 'اجمالي', 'شهر', 'سنه', 'سنتين'] as $calculation) {
        if (str_contains($normalizedMessage, $calculation)) {
            return false;
        }
    }

    return true;
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

