<?php

namespace App\Services;

class AiPromptBuilder
{
    /** Defensive caps only — normal messages/memory never come close to these. */
    private const MAX_MESSAGE_CHARS = 4000;
    private const MAX_MEMORY_CHARS = 20000;

    public function buildChatReplyPrompt(
        string $message,
        string $memoryContext,
        string $intent = 'fallback_complex',
        string $confidence = 'system',
        array $conversationContext = []
    ): string {
        $message = mb_substr($message, 0, self::MAX_MESSAGE_CHARS);
        $memoryContext = mb_substr($memoryContext, 0, self::MAX_MEMORY_CHARS);

        $conversationText = $this->formatConversation($conversationContext);

        /*
         * Plan task 3.5: one line about a returning customer (name, job, the
         * machine and term they were looking at). Rendered as an empty string
         * when unknown so the prompt shape never changes.
         */
        $profile = trim((string) ($conversationContext['customer_profile'] ?? ''));
        $profileBlock = $profile !== ''
            ? "\n\nاللي نعرفه عن العميل ده من تعاملات سابقة (للسياق - متقولهوش إننا مسجلينه):\n{$profile}"
            : '';
        $lastMachinesText = $this->formatLastMachines($conversationContext['last_machines'] ?? []);

        /*
         * The exact numbers of the scenario the customer is standing in,
         * straight from InstallmentCalculator. Without it the model could
         * only see the text of our earlier replies, so a new arithmetic
         * question ("طب الإجمالي كام؟") had nothing to work from and the
         * only safe move left was repeating the last message - which is
         * precisely what it kept doing.
         */
        $snapshotBlock = $this->formatInstallmentSnapshot($conversationContext['installment_snapshot'] ?? []);

        /*
         * Set only when this call is answering ONE part of a message that
         * had several requests in it - the other parts already went out as
         * their own message, so repeating them here reads as a stutter.
         */
        $focus = trim((string) ($conversationContext['step_focus'] ?? ''));
        $focusBlock = $focus !== ''
            ? "\n\nمهم: رسالة العميل فيها أكتر من طلب، والطلبات التانية اترد عليها في رسالة منفصلة خلاص. في الرد ده اتكلم عن {$focus} - ممنوع تكرر السعر أو أي إجابة تانية، وممنوع تقفل بسؤال لو مش لازم."
            : '';

        /*
         * Without this the model had no idea an application was in
         * progress, what was already collected, or what's still missing -
         * so a policy/side question mid-application got answered with no
         * awareness of the flow, and the model could ask again for a field
         * already given (see AI_WHATSAPP_BOT_MEMORY_INTELLIGENCE_AUDIT.md
         * §2/§3: "the AI that writes the reply cannot see the application
         * state").
         */
        $applicationStateBlock = $this->formatApplicationState($conversationContext['application_state'] ?? null);

        return <<<PROMPT
أنت موظف سيلز بشري محترف لمعرض موتوسيكلات Moto Gate

مهمتك:
ترد على رسالة العميل الحالية فقط.
Laravel هو المسؤول عن الرد على: السعر، الصور، حساب القسط، نظام التقسيط، الموديلات.
أما الفروع والعناوين واللوكيشن والتوصيل والأسئلة الحسابية والأسئلة العامة فإنت اللي بترد عليها من الميموري ومن أرقام السيناريو تحت.
أنت لا تحدد intent ولا تصنف الرسالة.

ممنوع:
- تقول إنك AI أو بوت.
- تقول إنك fallback أو إن Laravel بيرد.
- تقول إن عندك ميموري أو تعليمات.
- تخترع أسعار أو أقساط أو موديلات أو فروع من دماغك (الأرقام اللي تحت بس هي المسموح بيها).
- تذكر سعر تقسيط أساسي.
- تقول أي معلومة عن بلد المنشأ أو الماركة الحقيقية أو مواصفات فنية لموديل مش موجودة في الميموري تحت أو في اسم الموديل نفسه. اسم الموديل زي "هوجن N-MAX" معناه الماركة هوجن، مش أي ماركة عالمية حقيقية بنفس الاسم أو اسم قريب - ممنوع تستخدم معلوماتك العامة عن موديلات عالمية مشهورة بنفس الاسم.
- لو حد سأل عن مواصفات/بلد منشأ موديل ومفيش معلومة عنه في الميموري، قول إنك هتتأكد وترجعله، ومتخترعش إجابة.
- تستخدم markdown أو JSON.
- تبدأ بتحية إلا لو العميل بدأ بتحية.
- تقول "يا بطل".
- ترد كأن دي أول رسالة.
- تطلب اسم الموديل لو واضح من السياق أو من آخر موديلات.
- تدخل في تفاصيل سعر/صور/قسط لو الرسالة المفروض Laravel يرد عليها.

قواعد الرد:
- الرد مصري طبيعي، محترم، قصير - زي واحد قاعد في المعرض بيرد على واتساب، مش زي رسالة جاهزة.
- رسالة واحدة مناسبة للواتساب.
- ابدأ من اللي العميل قاله بالظبط. لو قال حاجة عن نفسه أو شغله أو ظروفه، اعترف بيها في أول سطر قبل ما تكمل.
- ممنوع تمامًا تبعت نفس آخر رسالة بعتناها للعميل تاني بنفس الصياغة أو قريب منها. لو الرد اللي في دماغك شبه آخر رسالة في السياق فوق، يبقى إنت مجاوبتش على سؤاله - جاوب على السؤال الجديد نفسه.
- **إنت مسموحلك تحسب**. الأرقام اللي في بلوك "أرقام السيناريو الحالي" تحت أرقام حقيقية من النظام - اجمع واطرح واضرب فيها عادي لو العميل سأل سؤال حسابي (إجمالي، فرق، كام هدفع في الآخر، كام الباقي). المهم متغيرش الأرقام الأساسية ومتخترعش رقم مش مبني عليها.
- لو العميل سأل سؤال حسابي ومفيش أرقام في البلوك ده، اسأله سؤال واحد قصير يكمّل الناقص (المدة مثلاً) بدل ما تعيد رسالة قديمة.
- لو العميل محتار، اسأله سؤال واحد يساعد البيع: استخدامه، ميزانيته، كاش ولا تقسيط.
- لو محتاج تدخل بشري، قل له إن حد من المعرض هيتابع معاه.
- لو العميل بيتكلم عن "هي / ده / دي / دول / سعرها / صورها / قسطها"، افهمها من آخر موديلات وسياق المحادثة.
- لو العميل سأل عن مكان المعرض أو الفروع أو العنوان، ابعتله الفروع بعناوينها وروابط اللوكيشن زي ما هي في الميموري، منسّقة وكل فرع في سطور لوحده، من غير ما يطلب اللوكيشن تاني.
- لو السؤال عن سعر/صور/قسط/موديلات بشكل مباشر، رد رد مكمل قصير بدون اختراع تفاصيل.
- الميموري تحت دي هي مصدر السياسات والشروط والفروع - لو معلومة سياسية فيها (فروع، شروط، أي حاجة غير رقمية) بتختلف عن رد قديم قلته في نفس المحادثة، اعتمد عليها وصحح المعلومة. لكن الأرقام (سعر، قسط، مصاريف إدارية) مصدرها الوحيد بلوك "أرقام السيناريو الحالي" تحت، وبيانات التقديم مصدرها الوحيد بلوك "حالة طلب التقسيط" تحت - الميموري ممنوع تغيّر أو "تصحح" أي رقم أو بيانة موجودة في البلوكين دول.
- لو بلوك "حالة طلب التقسيط" تحت موجود وفيه بيانة معينة (زي الاسم أو الوظيفة) مكتوب إنها معروفة بالفعل، ممنوع تسأل عنها تاني - كمّل من غيرها.

أرقام السيناريو الحالي (محسوبة من النظام - دي أرقام صح ومسموح تحسب عليها):
{$snapshotBlock}
{$applicationStateBlock}
الميموري النشطة من ai_memories (سياسات وشروط - المصدر الحالي لغير الأرقام):
{$memoryContext}

آخر رسايل من المحادثة بصيغة العميل/المعرض:
{$conversationText}

آخر الموديلات المرتبطة بالمحادثة من last_machine_ids:
{$lastMachinesText}{$profileBlock}

رسالة العميل الحالية:
{$message}{$focusBlock}

اكتب الرد النهائي فقط:
PROMPT;
    }

    /**
     * Renders the calculator snapshot as labelled Arabic lines. Returns a
     * short "nothing calculated yet" note rather than an empty block so the
     * prompt shape never changes.
     */
    /**
     * $applicationState shape (built by the caller from context_payload):
     * ['pending_question' => string|null, 'known' => ['اسم' => 'value', ...],
     *  'missing' => ['label', ...]]. Returns '' when there's no application
     * in progress, so the prompt shape is unchanged for the common case.
     */
    private function formatApplicationState(?array $applicationState): string
    {
        if (empty($applicationState) || empty($applicationState['pending_question'])) {
            return '';
        }

        $known = $applicationState['known'] ?? [];
        $missing = $applicationState['missing'] ?? [];

        $lines = ["- الخطوة الحالية: {$applicationState['pending_question']}"];

        if (! empty($known)) {
            $knownText = implode('، ', array_map(
                fn ($label, $value) => "{$label}: {$value}",
                array_keys($known),
                array_values($known)
            ));
            $lines[] = "- معروف بالفعل (ممنوع تسأل عنه تاني): {$knownText}";
        }

        if (! empty($missing)) {
            $lines[] = '- لسه ناقص: ' . implode('، ', $missing);
        }

        return "\nحالة طلب التقسيط الحالي (لو العميل سأل سؤال جانبي، جاوبه وبعدين فكّره بس باللي ناقص من هنا - من غير ما تعيد كل البيانات):\n"
            . implode("\n", $lines) . "\n";
    }

    private function formatInstallmentSnapshot(array $snapshot): string
    {
        if (empty($snapshot)) {
            return 'مفيش حسبة قسط متعملة في المحادثة دي لحد دلوقتي.';
        }

        $labels = [
            'machine_name' => 'الموديل',
            'cash_price' => 'سعر الكاش',
            'installment_price' => 'سعر التقسيط',
            'available_months' => 'المدد المتاحة (شهور)',
            'months' => 'المدة المتفق عليها (شهر)',
            'system' => 'النظام',
            'deposit' => 'المقدم',
            'monthly_payment' => 'القسط الشهري',
            'admin_fee' => 'المصاريف الإدارية',
            'installments_total' => 'مجموع الأقساط على المدة كلها',
            'due_at_pickup' => 'المطلوب وقت استلام المكنة (مقدم + مصاريف إدارية)',
            'grand_total' => 'الإجمالي الكلي من أول الاستلام لآخر قسط',
            'first_installment_after_days' => 'أول قسط بعد الاستلام بـ (يوم)',
        ];

        $lines = [];

        foreach ($labels as $key => $label) {
            if (! array_key_exists($key, $snapshot) || $snapshot[$key] === null || $snapshot[$key] === '') {
                continue;
            }

            $value = $snapshot[$key];

            if (is_array($value)) {
                $value = implode(' / ', $value);
            } elseif (is_numeric($value) && ! in_array($key, ['months', 'first_installment_after_days'], true)) {
                $value = number_format((float) $value) . ' جنيه';
            }

            $lines[] = "- {$label}: {$value}";
        }

        return empty($lines)
            ? 'مفيش حسبة قسط متعملة في المحادثة دي لحد دلوقتي.'
            : implode("\n", $lines);
    }

    private function formatConversation(array $conversationContext): string
    {
        $messages = $conversationContext['messages']
            ?? $conversationContext['recent_messages']
            ?? [];

        if (! is_array($messages) || empty($messages)) {
            return 'لا يوجد سياق محادثة سابق.';
        }

        $lines = [];

        foreach (array_slice($messages, -20) as $row) {
            $sender = $row['sender'] ?? $row['role'] ?? $row['direction'] ?? null;
            $body = trim((string) ($row['body'] ?? $row['message'] ?? $row['text'] ?? ''));
            $body = mb_substr($body, 0, 1000);

            if ($body === '') {
                continue;
            }

            $label = match ($sender) {
                'customer', 'client', 'user', 'inbound', 'incoming' => 'العميل',
                'shop', 'agent', 'assistant', 'bot', 'outbound', 'outgoing' => 'المعرض',
                default => 'غير محدد',
            };

            $lines[] = "{$label}: {$body}";
        }

        return empty($lines)
            ? 'لا يوجد سياق محادثة سابق.'
            : implode("\n", $lines);
    }

    private function formatLastMachines(array $machines): string
    {
        if (empty($machines)) {
            return 'لا يوجد موديلات أخيرة واضحة.';
        }

        $lines = [];

        foreach ($machines as $machine) {
            if (is_array($machine)) {
                $id = $machine['id'] ?? $machine['machine_id'] ?? null;
                $name = $machine['name'] ?? $machine['machine_name'] ?? null;

                $line = trim(($id ? "ID: {$id}" : '') . ($name ? " | Name: {$name}" : ''));

                if ($line !== '') {
                    $lines[] = $line;
                }

                continue;
            }

            $lines[] = (string) $machine;
        }

        return empty($lines)
            ? 'لا يوجد موديلات أخيرة واضحة.'
            : implode("\n", $lines);
    }
}
