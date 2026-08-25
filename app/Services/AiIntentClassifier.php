<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\WhatsappConversation;
use Illuminate\Support\Facades\Log;

class AiIntentClassifier
{
    /** Defensive cap only — a normal WhatsApp message never comes close to this. */
    private const MAX_MESSAGE_CHARS = 4000;

    /** Max independent extra requests (see steps[] in the prompt) handled per message. */
    private const MAX_EXTRA_STEPS = 2;

    public function classify(WhatsappConversation $conversation, string $message, array $context = []): array
    {
        $message = mb_substr($message, 0, self::MAX_MESSAGE_CHARS);

        $recent = $this->recentMessagesForPrompt($conversation);

        $lastMachines = $this->lastMachines($conversation);

        if (($context['mode'] ?? null) === 'application_data_extraction') {
            return $this->extractApplicationData($conversation, $message, $recent, $lastMachines, $context);
        }

        $prompt = $this->prompt($conversation, $message, $recent, $lastMachines, $context);

        try {
            $json = $this->requestPlanJson($prompt);

            if (! is_array($json)) {
                /*
                 * Gemini 3.x spends part of maxOutputTokens on internal
                 * thinking, so a long prompt (this one now carries the whole
                 * memory context) can come back truncated mid-JSON or with no
                 * text part at all. Falling straight through to fallback()
                 * means intent=unknown for a message we could have understood,
                 * so retry once with thinking off - the entire budget then
                 * goes to the JSON. Costs a second call only on failure.
                 */
                $json = $this->requestPlanJson($prompt, ['thinkingBudget' => 0]);
            }

            if (! is_array($json)) {
                return $this->fallback();
            }

            return $this->normalizePlan(array_merge($this->fallback(), $json));
        } catch (\Throwable $e) {
            Log::error('AI conversation planner failed', [
                'message' => $e->getMessage(),
            ]);

            return $this->fallback();
        }
    }

    /**
     * One planner call: returns the decoded JSON object, or null when the
     * call failed, was truncated, or did not contain a JSON object.
     */
    private function requestPlanJson(string $prompt, array $extraOptions = []): ?array
    {
        /*
         * Two latency fixes live here.
         *
         * responseMimeType pins the answer to JSON at the API level, so the
         * model can no longer wrap it in prose or a ```json fence - which is
         * what used to make extractJson() fail and cost us a second full
         * planner round trip.
         *
         * thinkingBudget bounds the internal thinking that Gemini 3.x spends
         * before the first output token, on a prompt that carries the whole
         * memory context plus the customer profile. The planner only has to
         * fill a fixed JSON shape, so a bounded budget is plenty.
         */
        $result = app(GeminiClient::class)->generateText($prompt, config('gemini.models.reasoning'), array_merge([
            'temperature' => 0.05,
            'maxOutputTokens' => 2048,
            'responseMimeType' => 'application/json',
            'thinkingBudget' => (int) config('gemini.planner.thinking_budget', 512),
            /*
             * Deliberately far below GeminiClient's 25s default. The planner
             * model answers in about a second when it is healthy, so a call
             * still running after 12s is stuck, not slow - and every one of
             * those seconds is a customer staring at no reply. Failing fast
             * hands the message to the next key instead.
             */
            'timeout' => (int) config('gemini.planner.timeout', 12),
        ], $extraOptions));

        if (! ($result['ok'] ?? false)) {
            return null;
        }

        $json = $this->extractJson(trim((string) ($result['reply'] ?? $result['text'] ?? '')));

        return is_array($json) ? $json : null;
    }

    private function prompt(
        WhatsappConversation $conversation,
        string $message,
        array $recent,
        array $lastMachines,
        array $context
    ): string {
        /*
         * The planner used to run completely blind to ai_memories - it had to
         * understand branch names, installment systems, excluded professions
         * and model aliases with none of them in front of it. The memory is
         * given here for comprehension only; the reply itself is still built
         * by Laravel or by AiComplexReplyService.
         */
        $memoryContext = app(AiMemoryContextBuilder::class)
            ->buildForMessage($message, [
                'conversation_id' => $conversation->id,
                'intent' => $conversation->last_topic ?? null,
            ])['context'] ?? '';

        // Plan task 3.5 - lets the planner resolve "زي المرة اللي فاتت" and
        // stop re-asking for a term the customer already settled on.
        $profile = app(CustomerProfileService::class)->summaryFor($conversation);

        $payload = [
            'current_message' => $message,
            'customer_profile' => $profile,
            'conversation_state' => [
                'last_machine_id' => $conversation->last_machine_id ?? null,
                'last_machine_ids' => $conversation->last_machine_ids ?? [],
                'last_topic' => $conversation->last_topic ?? null,
                'pending_question' => $conversation->pending_question ?? null,
                'context_payload' => $conversation->context_payload ?? [],
            ],
            'last_machines_shown_to_customer' => $lastMachines,
            'recent_messages' => $recent,
            'known_context' => $context,
        ];

        return <<<PROMPT
أنت عقل فهم محادثة لبوت واتساب معرض موتوسيكلات.

مهمتك ليست الرد على العميل.
مهمتك فهم الرسالة الحالية داخل سياق المحادثة وإرجاع خطة تنفيذ JSON فقط.

ممنوع:
- ممنوع تكتب شرح.
- ممنوع تكتب رد عادي.
- ممنوع Markdown.
- ممنوع أي نص خارج JSON.

Laravel هو الذي سينفذ:
- البحث عن المكن
- حساب القسط
- إرسال الصور
- بدء طلب التقديم
- سؤال العميل لو محتاج توضيح

النوايا المتاحة intent:
- price
- images
- installment_calc
- installment_total
- installment_system
- admin_fee_explanation
- brand_models
- branches
- application
- application_status
- delivery_question
- general
- unknown

target:
- new_machine: العميل ذكر موديل/ماركة جديدة في الرسالة الحالية
- previous_selection: العميل يقصد كل آخر الموديلات المعروضة في السياق
- single_previous_machine: العميل يقصد مكنة واحدة سابقة
- selected_index: العميل اختار رقم/ترتيب من آخر قائمة، مثل الأولى، التانية، آخر واحدة
- unknown

قواعد الفهم:
- افهم العامية المصرية والكتابة الغلط.
- الضمائر مثل: دي، ده، دول، هم، هما، الاتنين، كلها، عليهم، عليها ترجع للسياق السابق.
- لو آخر رد عرض أكثر من مكنة والعميل قال احسبهم/صورهم/سعرهم/عايزهم، target = previous_selection.
- لو آخر رد عرض أكتر من مكنة (last_machines_shown_to_customer) وكتب العميل اسم/كلمة قصيرة بتطابق واحدة أو أكتر منهم بس مش كلهم (زي اسم براند أو جزء من اسم موديل معروض بالفعل)، فده تضييق مش طلب جديد: target = previous_selection مع uses_last_machines=true، مش machine_query لبحث من الصفر.
- تأكيد بسيط زي "تمام"، "ماشي"، "اوك"، "حلو" لوحده بعد ما عرضنا مكنة أو أكتر (وملهوش علاقة بسؤال تقسيط قبله)، معناه العميل موافق ومكمل معاك على نفس اللي عرضته - مش طلب جديد ومش لازم تسأله عاوز مكنه ايه. target = previous_selection لو أكتر من مكنة، أو single_previous_machine لو واحدة بس.
- لو آخر رد عرض مكنة واحدة والعميل سأل عليها، target = single_previous_machine.
- لو العميل قال الأولى/التانية/التالتة/الأخيرة، target = selected_index واكتب selected_index رقم يبدأ من 1.
- لو العميل قال عايز أقدم وفيه أكثر من مكنة في السياق ولم يحدد واحدة، اجعل needs_clarification=true.
- لو العميل قال عايز أقدم وفيه مكنة واحدة فقط في السياق، intent=application و target=single_previous_machine.
- لو العميل ذكر موديل جديد، استخرج machine_query.
- لو العميل سأل عن القسط أو مدة تقسيط، intent=installment_calc.
- installment_total: العميل بيسأل عن الإجمالي اللي هيدفعه في الآخر أو على المدة كلها أو مجموع الأقساط أو "هيطلعلي بكام في الآخر" أو "اجمالي المبلغ" أو "كام كل اللي هدفعه". دي مش installment_calc - دي طلب مجموع، والحساب الشهري غالبًا اتعمل قبل كده في المحادثة.
- لو العميل سأل عن أنظمة/شروط/ورق التقسيط بشكل عام (مين ينفع يقسط، إيه المستندات، إيه الأنظمة المتاحة)، intent=installment_system.
- admin_fee_explanation: العميل بيسأل عن المصاريف الإدارية نفسها - "ايه هي المصاريف الادارية"، "المصاريف الادارية دي ايه"، "نسبتها كام"، "بتتدفع امتى"، "ليه بندفعها". دي **مش** installment_system: العميل مش بيسأل عن أنظمة التقسيط ولا الشروط، هو بيسأل عن بند واحد بس. ممنوع ترجع installment_system لسؤال عن المصاريف الإدارية.
- branches: العميل بيسأل عن مكان المعرض/الفروع/العنوان/اللوكيشن - "مكانكم فين"، "العنوان ايه"، "فين المعرض"، "ابعتلي اللوكيشن"، "عندكم فروع فين"، "اقرب فرع".
- لو العميل سأل عن الصور/الشكل/الألوان، intent=images.
- لو العميل سأل عن السعر/الكاش/بكام، intent=price.
- لو الرسالة ناقصة لكن يمكن فهمها من السياق، لا تطلب توضيح.
- لا تطلب توضيح إلا لو التنفيذ خطر أو فيه أكثر من اختيار ولا يوجد تحديد.
- machine_query لازم يكون اسم الموديل أو الماركة فقط من رسالة العميل، بدون كلمات طلب مثل عايز/احسب/سعر/صور.
- لو العميل كتب "كي تي اكس" اجعل machine_query = "كي تي اكس".
- لو العميل كتب "هوجن 4" لا تجعل machine_query = "هوجن" فقط، لازم "هوجن 4".
- لو last_topic = application والعميل يسأل "إيه المطلوب؟" أو "أعمل إيه؟" فـ intent=application و target=single_previous_machine ولا تسأل عن الموديل لو last_machine_ids موجودة.
- أي رسالة فيها معنى التقديم مثل: اقدم ازاي، عايز اقدم، اقدم، تقديم، ايه المطلوب للتقديم = intent application.
- لو last_machine_ids موجودة والعميل قال اقدم ازاي أو عايز اقدم أو ايه المطلوب، target = single_previous_machine.
- ممنوع تسأل عن اسم المكنة في application لو last_machine_ids موجودة.
- لو last_topic = application والعميل قال ايه المطلوب أو أعمل ايه، intent=application و target=single_previous_machine.
- application_status: العميل عنده طلب موجود بالفعل ويريد الاستعلام عن حالته أو أين وصل. مثال: "طلبي وصل لايه"، "حالة طلبي ايه"، "طلبي فين"، "الطلب بتاعي وصل لفين"، "عايز اعرف حالة طلبي"، "إيه أخبار طلبي"، "هل طلبي اتوافق عليه"، "طلبي لسه تحت المراجعة؟". هذه النية تعني الاستعلام فقط وليس إنشاء طلب جديد. لا تستخدم application_status للتقديم أو استكمال طلب جديد.
- delivery_question: العميل بيسأل عن التوصيل بس (تكلفة التوصيل، مدته، هل بيوصل لمنطقته) - مش عن سعر أو تفاصيل المنتج نفسه. استخدم delivery_question حتى لو العميل وسط طلب تقديم لسه مكملوش، طالما السؤال ده عن التوصيل بس.

steps (طلبات إضافية مستقلة في نفس الرسالة):
- لو العميل طلب أكتر من حاجة مستقلة في نفس الرسالة (زي "سعرها وصورها"، أو "احسبلي القسط وابعتلي صورها")، الحقول العادية فوق (intent, target, machine_query, ...) بتمثل الطلب الأساسي بس، وأي طلب إضافي بعد كده يتحط كـ عنصر في steps[] بنفس شكل الحقول دي بالظبط (نفس الأسماء: intent, target, machine_query, months, deposit, system, ...).
- steps[] للطلبات الإضافية فقط - متكررش الطلب الأساسي جواها.
- steps[] بتتبني من **الرسالة الحالية بس**. ممنوع تمامًا تحط فيها طلب اتقال في رسالة قديمة وإحنا رددنا عليه خلاص (زي إن العميل سأل عن الفروع في رسالة فاتت) - ده بيخلي البوت يبعت نفس الإجابة تاني بلا سبب.
- لو الرسالة فيها طلب واحد بس، سيب steps[] = [].
- حد أقصى عنصرين في steps[].
- ممنوع تحط intent=application أو application_status جوه steps[] - دول لازم يكونوا الطلب الأساسي بس.
- لو طلب إضافي غامض أو ناقص معلومة، سيبه برضو (بدون needs_clarification) - التنفيذ هيتجاهله لو مش واضح، أحسن من إنك توقف الرد كله عشانه.

تحويل المدد:
- سنة = 12
- سنة ونص = 18
- سنتين = 24
- تلات سنين / 3 سنين = 36
- 12 شهر = 12
- 18 شهر = 18
- 24 شهر = 24
- 36 شهر = 36

system:
- نظام 20 أو بمصاريف إدارية = "20"
- نظام 30 أو بدون مصاريف إدارية = "30"
- غير مذكور = null

رجع JSON بنفس الشكل فقط:

{
  "intent": "unknown",
  "target": "unknown",
  "machine_query": null,
  "machine_ids": [],
  "selected_index": null,
  "uses_last_machines": false,
  "references_previous": false,
  "references_all_previous": false,
  "months": null,
  "deposit": null,
  "deposit_mentioned": false,
  "system": null,
  "needs_machine": false,
  "needs_months": false,
  "needs_clarification": false,
  "clarification_reason": null,
  "clarification_question": null,
  "confidence": 0.0,
  "steps": [
    {
      "intent": "images",
      "target": "new_machine",
      "machine_query": null,
      "machine_ids": [],
      "selected_index": null,
      "uses_last_machines": false,
      "references_previous": false,
      "references_all_previous": false,
      "months": null,
      "deposit": null,
      "deposit_mentioned": false,
      "system": null,
      "confidence": 0.0
    }
  ]
}

معلومات المعرض (للفهم فقط - ممنوع ترد بيها، دي بس عشان تفهم قصد العميل صح):
{$memoryContext}

البيانات:
PROMPT
        . "\n" . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Last 20 messages, with the full raw payload (which can carry a large
     * OCR text blob for a document upload) kept only for the single most
     * recent message that actually has one. Older OCR/media payloads in the
     * window are collapsed to a short summary — their extracted data is
     * already reflected in conversation_state/context_payload, so resending
     * the full document text on every later message is pure duplication,
     * not extra understanding.
     */
    private function recentMessagesForPrompt(WhatsappConversation $conversation): array
    {
        $rows = $conversation->messages()
            ->latest()
            ->take(20)
            ->get()
            ->reverse()
            ->values();

        $lastRichPayloadIndex = null;

        foreach ($rows as $i => $m) {
            if ($this->payloadHasDocumentData($m->payload ?? null)) {
                $lastRichPayloadIndex = $i;
            }
        }

        return $rows
            ->map(fn ($m, $i) => [
                'direction' => $m->direction,
                'message' => $m->message,
                'payload' => $this->promptPayload($m->payload ?? null, $i === $lastRichPayloadIndex),
            ])
            ->values()
            ->all();
    }

    private function payloadHasDocumentData($payload): bool
    {
        return is_array($payload)
            && (! empty($payload['ocr_results']) || ! empty($payload['saved_media_items']));
    }

    private function promptPayload($payload, bool $keepFull)
    {
        if (! is_array($payload) || $keepFull || ! $this->payloadHasDocumentData($payload)) {
            return $payload;
        }

        $summary = [];

        if (is_array($payload['saved_media_items'] ?? null)) {
            $summary['media_count'] = count($payload['saved_media_items']);
            $summary['media_types'] = collect($payload['saved_media_items'])
                ->pluck('type')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if (isset($payload['ocr_status'])) {
            $summary['ocr_status'] = $payload['ocr_status'];
        }

        if (is_array($payload['ocr_results'] ?? null)) {
            $summary['ocr_documents_processed'] = count($payload['ocr_results']);
        }

        return $summary ?: null;
    }

    private function lastMachines(WhatsappConversation $conversation): array
    {
        $ids = $conversation->last_machine_ids ?? [];

        if (is_string($ids)) {
            $ids = json_decode($ids, true) ?: [];
        }

        if (! is_array($ids)) {
            $ids = [];
        }

        if (empty($ids) && ! empty($conversation->last_machine_id)) {
            $ids = [(int) $conversation->last_machine_id];
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn (int $id) => $id > 0
        )));

        if (empty($ids)) {
            return [];
        }

        return Machine::query()
            ->with('brand')
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn ($machine) => array_search($machine->id, $ids, true))
            ->values()
            ->map(fn (Machine $machine, int $i) => [
                'position' => $i + 1,
                'id' => $machine->id,
                'name' => $machine->name,
                'brand' => $machine->brand?->name,
                'display_name' => trim(($machine->brand?->name ? $machine->brand->name . ' ' : '') . $machine->name),
                'cash_price' => $machine->cash_price,
                'installment_price' => $machine->installment_price,
            ])
            ->all();
    }

    private function fallback(): array
    {
        return [
            'intent' => 'unknown',
            'target' => 'unknown',
            'machine_query' => null,
            'machine_ids' => [],
            'selected_index' => null,
            'uses_last_machines' => false,
            'references_previous' => false,
            'references_all_previous' => false,
            'months' => null,
            'deposit' => null,
            'deposit_mentioned' => false,
            'system' => null,
            'needs_machine' => false,
            'needs_months' => false,
            'needs_clarification' => false,
            'clarification_reason' => null,
            'clarification_question' => null,
            'confidence' => 0.0,
            // Extra independent requests in the same message (e.g. "سعرها
            // وصورها"). Each entry has the exact same shape as this plan.
            'steps' => [],
        ];
    }

    private function normalizePlan(array $plan): array
    {
        $rawSteps = is_array($plan['steps'] ?? null) ? $plan['steps'] : [];

        $plan = $this->normalizePlanFields($plan);

        /*
         * Extra steps are independent additional requests in the same
         * message (e.g. "سعرها وصورها"). Each is normalized through the
         * exact same rules as the primary plan, so router handlers written
         * for a single flat plan array work unchanged on a step too.
         * Capped at MAX_EXTRA_STEPS as a safety limit, and application/
         * application_status can never come from here - starting or
         * mutating an application from a secondary request is unsafe.
         */
        $steps = [];

        foreach (array_slice($rawSteps, 0, self::MAX_EXTRA_STEPS) as $rawStep) {
            if (! is_array($rawStep)) {
                continue;
            }

            $step = $this->normalizePlanFields(array_merge($this->fallback(), $rawStep));

            if (in_array($step['intent'], ['application', 'application_status', 'unknown', 'general'], true)) {
                continue;
            }

            $step['needs_clarification'] = false;
            $step['clarification_question'] = null;
            // A step is never itself a multi-step plan - drop anything the
            // model nested here so it can't be carried around unexecuted.
            $step['steps'] = [];

            $steps[] = $step;
        }

        $plan['steps'] = $steps;

        return $plan;
    }

    /**
     * Validates/casts one plan-shaped array (the primary plan, or one entry
     * of $plan['steps']). Never touches the 'steps' key itself.
     */
    private function normalizePlanFields(array $plan): array
    {
        $validIntents = [
            'price',
            'images',
            'installment_calc',
            'installment_total',
            'installment_system',
            'admin_fee_explanation',
            'brand_models',
            'branches',
            'application',
            'application_status',
            'delivery_question',
            'general',
            'unknown',
        ];

        $validTargets = [
            'new_machine',
            'previous_selection',
            'single_previous_machine',
            'selected_index',
            'unknown',
            'last_machines',
        ];

        if (! in_array($plan['intent'], $validIntents, true)) {
            $plan['intent'] = 'unknown';
        }

        if (! in_array($plan['target'], $validTargets, true)) {
            $plan['target'] = 'unknown';
        }

        if ($plan['target'] === 'last_machines') {
            $plan['target'] = 'previous_selection';
        }

        if (in_array($plan['target'], ['previous_selection', 'single_previous_machine', 'selected_index'], true)) {
            $plan['uses_last_machines'] = true;
            $plan['references_previous'] = true;
        }

        if ($plan['target'] === 'previous_selection') {
            $plan['references_all_previous'] = true;
        }

        $plan['machine_ids'] = is_array($plan['machine_ids']) ? $plan['machine_ids'] : [];
        $plan['confidence'] = max(0, min(1, (float) $plan['confidence']));

        if (! in_array((string) $plan['system'], ['20', '30'], true)) {
            $plan['system'] = null;
        }

        foreach (['months', 'selected_index'] as $key) {
            $plan[$key] = $plan[$key] !== null ? (int) $plan[$key] : null;
        }

        $plan['deposit'] = $plan['deposit'] !== null ? (float) $plan['deposit'] : null;

        return $plan;
    }
private function extractApplicationData(
    WhatsappConversation $conversation,
    string $message,
    array $recent,
    array $lastMachines,
    array $context
): array {
    $payload = [
        'current_message' => $message,
        'recent_messages' => $recent,
        'last_machines' => $lastMachines,
        'known_context' => $context,
    ];

    $prompt = <<<PROMPT
أنت مستخرج بيانات طلب تقسيط لمعرض موتوسيكلات.

ممنوع ترد على العميل.
ممنوع تشرح.
رجع JSON فقط.

استخرج من رسالة العميل وسياق المحادثة البيانات المتاحة فقط.
لو البيانات غير موجودة اتركها null.
لا تخترع أي بيانات.

الحقول:
- full_name
- national_id
- phone
- job_type
- income_proof
- work_address
- home_address
- installment_months
- work_vehicle

قواعد مهمة:
- لو known_context.required_fields فيها full_name وken_context.current_application.full_name
  لسه null، وجاءت رسالة العميل الحالية قصيرة (من كلمة لحد 5 كلمات)، مفيهاش
  أرقام كتير، ومش رد واضح على سؤال تاني (زي كاش/تقسيط أو عنوان)، اعتبرها
  full_name = نص الرسالة كامل بعد شيل كلمة "الاسم"/"اسمي"/"انا" لو موجودة
  في الأول (مثال: "كيرلس ناجي" -> full_name="كيرلس ناجي"، "الاسم كيرلس
  ناجي فهيم" -> full_name="كيرلس ناجي فهيم"). ده أهم قاعدة استخراج لأن
  العميل غالبًا بيرد بالاسم لوحده من غير أي سياق تاني.
- نفس الفكرة لـ national_id: لو الرسالة رقم بس (10 لـ14 خانة) ومش شكل رقم
  موبايل مصري (يعني مش بادئ بـ01)، اعتبرها national_id حتى لو مش بالظبط
  14 خانة (ممكن العميل يكتبه غلط أو ناقص رقم) - الأهم إنه مش رقم موبايل
  ومش عنوان. ولـ phone: لو الرسالة رقم موبايل مصري (01 وبعده 9 أرقام)،
  اعتبرها phone.
- العميل كتير بيرد على كل الأسئلة الناقصة دفعة واحدة في رسالة واحدة على
  عدة أسطر (اسم، رقم قومي، موبايل، عنوان شغل، عنوان سكن كل واحد في سطر).
  في الحالة دي حلل كل سطر لوحده بدل ما تطبق قاعدة full_name/national_id
  المبنية على "الرسالة كلها" حرفيًا: سطر رقمي بادئ بـ01 وطوله 11 -> phone.
  سطر رقمي تاني طوله 10-14 خانة -> national_id. سطر نصي قصير من غير أرقام
  ولسه full_name = null -> full_name. باقي الأسطر (عناوين أو job_type)
  زي القاعدة الموجودة تحت لتوزيع أسطر العنوان بالترتيب.
- لو العميل ذكر عنوان عام ناقص مثل: "ساكن في 12 ش محمد أبو النجا" اعتبره home_address لكنه ناقص.
- العنوان الكامل غالبًا يحتاج: محافظة أو منطقة + شارع + علامة مميزة أو رقم منزل/عمارة.
- لو عنوان السكن ناقص، ضع home_address_status = "incomplete".
- لو عنوان الشغل ناقص، ضع work_address_status = "incomplete".
- لو العنوان واضح وكامل، status = "complete".
- work_address وhome_address حقلين منفصلين تمامًا. ممنوع تدمج قيمة
  الاتنين في حقل واحد ولو العميل كتبهم في سطرين متتاليين مع بعض.
- العميل غالبًا بيرد على الأسئلة الناقصة (المذكورة في required_fields
  و known_context.current_application اللي لسه null) بنفس الترتيب اللي
  اتسألوا بيه. لو الرسالة فيها أكتر من سطر شبه عنوان (زي "في اكتوبر
  الحي الاول" و"اكتوبر الحي التاني")، وwork_address وhome_address كلاهم
  لسه null، اعتبر أول سطر عنوان = work_address والسطر اللي بعده =
  home_address (لأن السؤال بيتسأل بالترتيب ده). لو واحد بس منهم مذكور،
  حطه في الحقل الناقص المناسب بس، ومتخترعش الحقل التاني.
- لو العميل قال إنه شغال في مصنع أو موظف، job_type = "موظف/عامل مصنع".
- صيغ زي "انا شغال على المكنة"، "هشتغل عليها"، "شغال بيها"، "المكنة دي لشغلي"، "شغال طلبات"، "شغال دليفري"، "شغال اوبر/اندرايف/كريم"، "سواق تطبيقات" كلها معناها إنه هيستخدم الموتوسيكل في الشغل: job_type = "مندوب توصيل/سواق تطبيقات" وincome_proof = "لا يوجد". دي **إجابة على سؤال طبيعة الشغل**، مش رسالة فاضية - ممنوع تسيب job_type = null فيها.
- لو العميل ذكر إنه شغال عمل حر أو مهنة حرة أو حرفي/صنايعي (مثل: "سباك"، "نجار"، "حداد"، "كهربائي"، "نقاش"، "سواق"، "دليفري"، "صنايعي"، "شغال حر")، اجعل job_type = مهنته واجعل income_proof = "لا يوجد" تلقائيًا (لأن أصحاب المهن الحرة ليس لديهم مفردات مرتب).
- لو قال معاه مفردات مرتب أو مؤمن عليه، income_proof = وصف الإثبات (مثلاً "مفردات مرتب").
- income_proof سؤال بإجابة أكيدة (معاه ولا لأ)، مش حقل ممكن يفضل فاضي طول
  ما العميل رد عليه بأي صيغة. لو العميل قال إنه مالوش إثبات دخل بأي
  صيغة (مثلاً: "لا"، "مفيش"، "معايا"، "معيش"، "مش معايا"، "شغال حر")
  حتى لو الرسالة مقتصرة على كده، اعتبر income_proof = "لا يوجد" (قيمة
  نصية فعلية، مش null) - ده رد صريح مش بيانات ناقصة.
- نفس الفكرة بالظبط لـ work_address: لو العميل قال إنه معندوش مكان شغل
  ثابت بأي صيغة (مثلاً: "مليش مكان عمل"، "مفيش عنوان شغل"، "معنديش مقر"،
  "شغال من البيت"، "شغال متنقل"، أو أي عبارة نفي واضحة ردًا على سؤال عنوان
  الشغل - وده شائع عند الدليفري وسواقين التطبيقات والعمل الحر المتنقل)،
  اعتبر work_address = "لا يوجد" (قيمة نصية فعلية، مش null، وممنوع تحاول
  تفسرها كعنوان ناقص وتحطها في work_address_status = "incomplete").
- work_vehicle: نوع المركبة اللي العميل بيشتغل عليها فعلاً دلوقتي (مش
  المكنة اللي بيقدّم عليها). القيم المسموحة بس: "bicycle" أو "motorcycle"
  أو "car" أو null.
  * "عجلة"، "بسكلتة"، "بيسكلته"، "عجله" -> "bicycle"
  * "موتوسيكل"، "موتور"، "سكوتر"، "تروسيكل" -> "motorcycle"
  * "عربية"، "ملاكي"، "تاكسي"، "أوبر"، "كريم"، "ديدي"، "اندرايف" -> "car"
  متخمنش: لو العميل قال إنه دليفري أو سواق تطبيقات من غير ما يذكر
  المركبة، سيب work_vehicle = null. لو قال "شغال أوبر" من غير تفاصيل
  اعتبرها "car" لأن أوبر وكريم عربيات، أما "شغال طلبات/مرسول" لوحدها
  متحددش منها المركبة (null) لأنها بتتعمل بالعجلة والموتوسيكل الاتنين.

رجع JSON بهذا الشكل فقط:

{
  "application_data": {
    "full_name": null,
    "national_id": null,
    "phone": null,
    "job_type": null,
    "income_proof": null,
    "work_address": null,
    "work_address_status": null,
    "home_address": null,
    "home_address_status": null,
    "installment_months": null,
    "work_vehicle": null
  }
}

البيانات:
PROMPT
        . "\n" . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    try {
        $json = $this->requestPlanJson($prompt);

        if (! is_array($json)) {
            // Same truncation guard as classify() above: an extraction that
            // silently returns [] loses the customer's name/ID for that turn
            // and the flow asks for it again.
            $json = $this->requestPlanJson($prompt, ['thinkingBudget' => 0]);
        }

        return is_array($json) ? $json : ['application_data' => []];
    } catch (\Throwable $e) {
        Log::error('AI application extraction failed', [
            'message' => $e->getMessage(),
        ]);

        return ['application_data' => []];
    }
}
    private function extractJson(string $text): ?array
    {
        $text = trim($text);

        $text = preg_replace('/^```json\s*/i', '', $text);
        $text = preg_replace('/^```\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        if (preg_match('/\{.*\}/su', $text, $m)) {
            $text = $m[0];
        }

        $data = json_decode($text, true);

        return is_array($data) ? $data : null;
    }
}
