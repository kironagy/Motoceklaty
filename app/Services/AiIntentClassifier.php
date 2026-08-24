<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\WhatsappConversation;
use Illuminate\Support\Facades\Log;

class AiIntentClassifier
{
    /** Defensive cap only — a normal WhatsApp message never comes close to this. */
    private const MAX_MESSAGE_CHARS = 4000;

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
            $result = app(GeminiClient::class)->generateText($prompt, config('gemini.models.reasoning'), [
                'temperature' => 0.05,
                'maxOutputTokens' => 2048,
            ]);

            if (! ($result['ok'] ?? false)) {
                return $this->fallback();
            }

            $text = trim((string) ($result['reply'] ?? $result['text'] ?? ''));
            $json = $this->extractJson($text);

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

        $payload = [
            'current_message' => $message,
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
- installment_system
- brand_models
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
- لو العميل سأل عن أنظمة/شروط/ورق التقسيط، intent=installment_system.
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
  "confidence": 0.0
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
        ];
    }

    private function normalizePlan(array $plan): array
    {
        $validIntents = [
            'price',
            'images',
            'installment_calc',
            'installment_system',
            'brand_models',
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

قواعد مهمة:
- لو known_context.required_fields فيها full_name وken_context.current_application.full_name
  لسه null، وجاءت رسالة العميل الحالية قصيرة (من كلمة لحد 5 كلمات)، مفيهاش
  أرقام كتير، ومش رد واضح على سؤال تاني (زي كاش/تقسيط أو عنوان)، اعتبرها
  full_name = نص الرسالة كامل بعد شيل كلمة "الاسم"/"اسمي"/"انا" لو موجودة
  في الأول (مثال: "كيرلس ناجي" -> full_name="كيرلس ناجي"، "الاسم كيرلس
  ناجي فهيم" -> full_name="كيرلس ناجي فهيم"). ده أهم قاعدة استخراج لأن
  العميل غالبًا بيرد بالاسم لوحده من غير أي سياق تاني.
- نفس الفكرة لـ national_id: لو الرسالة رقم من 14 خانة بس، اعتبرها
  national_id. ولـ phone: لو الرسالة رقم موبايل مصري (01 وبعده 9 أرقام)،
  اعتبرها phone.
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
- لو العميل ذكر إنه شغال عمل حر أو مهنة حرة أو حرفي/صنايعي (مثل: "سباك"، "نجار"، "حداد"، "كهربائي"، "نقاش"، "سواق"، "دليفري"، "صنايعي"، "شغال حر")، اجعل job_type = مهنته واجعل income_proof = "لا يوجد" تلقائيًا (لأن أصحاب المهن الحرة ليس لديهم مفردات مرتب).
- لو قال معاه مفردات مرتب أو مؤمن عليه، income_proof = وصف الإثبات (مثلاً "مفردات مرتب").
- income_proof سؤال بإجابة أكيدة (معاه ولا لأ)، مش حقل ممكن يفضل فاضي طول
  ما العميل رد عليه بأي صيغة. لو العميل قال إنه مالوش إثبات دخل بأي
  صيغة (مثلاً: "لا"، "مفيش"، "معايا"، "معيش"، "مش معايا"، "شغال حر")
  حتى لو الرسالة مقتصرة على كده، اعتبر income_proof = "لا يوجد" (قيمة
  نصية فعلية، مش null) - ده رد صريح مش بيانات ناقصة.

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
    "installment_months": null
  }
}

البيانات:
PROMPT
        . "\n" . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    try {
        $result = app(GeminiClient::class)->generateText($prompt, config('gemini.models.reasoning'), [
            'temperature' => 0.05,
            'maxOutputTokens' => 2048,
        ]);

        if (! ($result['ok'] ?? false)) {
            return ['application_data' => []];
        }

        $json = $this->extractJson(trim((string) ($result['reply'] ?? $result['text'] ?? '')));

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
