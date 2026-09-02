<?php

namespace App\Services;

use App\Models\WhatsappConversation;
use App\Support\RepetitionGuard;
use Illuminate\Support\Facades\Log;

/**
 * مانع اللوب المركزي.
 *
 * قبل كده كان `RepetitionGuard` بيتنادى في مكان واحد بس - مسار الرد الحر
 * من الـ LLM (`WhatsappIntentRouter::handleAiFallback`). كل الردود
 * الحتمية (سؤال الموديل، "كاش ولا تقسيط"، "على كام شهر"، "ابعتلي اسم
 * المكنة"، "ابعتلي صورة البطاقة") كانت بتعدي من غير أي فحص، وde facto
 * دي كانت مصدر كل اللوبات اللي اتشافت في التجارب الحقيقية: نفس الجملة
 * حرفيًا ٤ مرات ورا بعض والعميل بيرد بحاجة مختلفة كل مرة.
 *
 * الكلاس ده بيتنادى مرة واحدة في `handle()` على *أي* رد خارج، مهما كان
 * الهاندلر اللي طلّعه. وفلسفته إن تكرار السؤال معناه إن العميل مش فاهم
 * السؤال - مش إنه بيتجاهله - فبنتصرف زي موظف خدمة عملاء حقيقي:
 *
 *   المرة ١ (أول تكرار): نعيد صياغة السؤال، نقول ليه محتاجينه، ونحط
 *                        الاختيارات صريحة قدامه.
 *   المرة ٢: نفتحله باب خروج - يعدّي السؤال ده ونكمل، أو يتكلم مع موظف.
 *   المرة ٣: تحويل لموظف بشري، بجملة توضح إننا مش هنلف تاني.
 *
 * العداد بيتصفّر أول ما يخرج رد مش مكرر.
 */
class ConversationLoopGuard
{
    /**
     * فوق الحد ده الرد بيتعتبر تكرار. ٠.٧٥ كان بيمسك تكرار حرفي وكمان
     * إعادة صياغة قريبة جدًا؛ سبناه زي ما هو عشان نفس السلوك اللي
     * الـ fallback كان بيقيس بيه.
     */
    private const REPEAT_THRESHOLD = 0.75;

    /** كام رسالة خارجة سابقة نقارن بيها. */
    private const LOOKBACK = 3;

    public function __construct(
        private readonly RepetitionGuard $repetition,
        private readonly GeminiClient $gemini,
    ) {
    }

    /**
     * بيقارن الرد الجديد باللي قبله ويرجّع:
     *   ['reply' => string|null, 'streak' => int, 'handoff' => bool]
     *
     * `reply` = الرد اللي المفروض يتبعت فعلًا (ممكن يكون هو هو لو مفيش
     * تكرار، أو نسخة متصعّدة لو فيه). `handoff` = true معناها إن الراوتر
     * لازم يحوّل لموظف بدل ما يبعت أي سؤال تاني.
     *
     * @param  array<int,string>  $previousOutgoing  آخر ردود خرجت *قبل* الدور ده
     */
    public function inspect(
        WhatsappConversation $conversation,
        string $reply,
        array $previousOutgoing,
        string $incomingMessage = ''
    ): array {
        $reply = trim($reply);

        if ($reply === '') {
            return ['reply' => $reply, 'streak' => 0, 'handoff' => false, 'score' => 0.0];
        }

        $previousOutgoing = array_slice(array_values(array_filter($previousOutgoing)), 0, self::LOOKBACK);

        $score = $this->repetition->score($reply, $previousOutgoing);

        if ($score < self::REPEAT_THRESHOLD) {
            $this->resetStreak($conversation);

            return ['reply' => $reply, 'streak' => 0, 'handoff' => false, 'score' => $score];
        }

        $streak = $this->bumpStreak($conversation);

        Log::info('ai_loop_guard', [
            'conversation_id' => $conversation->id,
            'streak' => $streak,
            'score' => round($score, 2),
            'incoming' => mb_substr($incomingMessage, 0, 120),
            'repeated_reply' => mb_substr($reply, 0, 160),
        ]);

        /*
         * رد معلوماتي (مش سؤال) اتكرر = إجابتنا مجاوبتش على سؤاله.
         * إعادة إرساله - حتى بمقدمة "أوضحهالك تاني" - هي بالظبط اللي
         * حصل في محادثات الإعلان: العميل سأل "فايده كام على السنة"
         * و"قسط مباشر ولا أبلكيشن" وخد نفس البلوك أربع مرات، وآخر مرة
         * والنظام عارف إنه مكرر بنسبة 97%.
         *
         * فالمحتوى المكرر ده مش بيتبعت خالص. بنقول للعميل صراحةً إن ردنا
         * مجاوبش، ونطلب منه يحدد سؤاله، ونعرض عليه موظف - وبعد مرتين
         * بنحوّله من غير لف.
         */
        if (! $this->isQuestion($reply)) {
            if ($streak >= 2) {
                return ['reply' => null, 'streak' => $streak, 'handoff' => true, 'score' => $score];
            }

            return [
                'reply' => 'واضح إن ردي مجاوبش على سؤال حضرتك، وأنا آسف على كده. '
                    . "قولي بالظبط عايز تعرف إيه (رقم معيّن، شرط، خطوة) وأنا أجاوبك على طول.\n"
                    . 'ولو تحب أوصلك بزميلي من الفريق قولي "عايز أكلم حد".',
                'streak' => $streak,
                'handoff' => false,
                'score' => $score,
            ];
        }

        if ($streak >= 3) {
            return ['reply' => null, 'streak' => $streak, 'handoff' => true, 'score' => $score];
        }

        $rewritten = $this->rewrite($conversation, $reply, $incomingMessage, $streak);

        return [
            'reply' => $rewritten,
            'streak' => $streak,
            'handoff' => false,
            'score' => $score,
        ];
    }

    /**
     * إعادة صياغة الرد المكرر. بيحاول الـ LLM الأول عشان الصياغة تطلع
     * طبيعية ومناسبة للسؤال نفسه، ولو فشل (ليمت/timeout) بيرجع لصياغة
     * حتمية - المهم إن العميل ميشوفش نفس الجملة تاني بأي حال.
     */
    private function rewrite(
        WhatsappConversation $conversation,
        string $reply,
        string $incomingMessage,
        int $streak
    ): string {
        $escapeHatch = $streak >= 2;

        $instruction = $escapeHatch
            ? <<<TXT
            العميل رد مرتين ورا بعض من غير ما يجاوب على السؤال ده، يعني هو
            غالبًا مش فاهم السؤال أو مش قادر يجاوبه دلوقتي.

            اكتب سطرين بحد أقصى: السؤال بأبسط صياغة ممكنة، وليه محتاجينه
            في نص سطر. ولو السؤال فيه اختيارات، لازم تسيب الاختيارات
            مكتوبة بالحرف في ردك.
            TXT
            : <<<TXT
            العميل رد على السؤال ده بحاجة تانية، يعني غالبًا مش فاهم قصدنا.

            اكتب رسالة قصيرة (سطرين بحد أقصى) تعيد نفس السؤال **بصياغة
            مختلفة تمامًا وأبسط**، وتوضح في نص سطر ليه احنا محتاجين
            المعلومة دي. ولو السؤال فيه اختيارات، لازم تسيب الاختيارات
            مكتوبة بالحرف في ردك.
            TXT;

        $prompt = <<<TXT
        إنت موظف خدمة عملاء مصري في معرض موتوسيكلات، بتتكلم مصري عامي طبيعي.

        السؤال اللي احنا بنكرره على العميل:
        ---
        {$reply}
        ---

        آخر رسالة من العميل:
        ---
        {$incomingMessage}
        ---

        {$instruction}

        قواعد إلزامية:
        - ممنوع تستخدم نفس جُمل السؤال القديم حرفيًا.
        - ممنوع تعتذر أو تقول "زي ما قلتلك" أو "أنا كررت السؤال".
        - ممنوع تطلب أي معلومة تانية غير اللي في السؤال ده.
        - ممنوع تخترع أسعار أو موديلات أو تفاصيل مش مذكورة فوق.
        - **كل رقم موجود في الرسالة فوق لازم يفضل موجود في ردك بالظبط**،
          وممنوع تضيف أي رقم جديد. إنت بتعيد الصياغة، مش بتلخّص.
        - رد بنص الرسالة بس، من غير أي شرح أو علامات تنصيص.
        TXT;

        try {
            $result = $this->gemini->generateText(
                prompt: $prompt,
                preferredModelCode: config('gemini.models.fast'),
                options: [
                    'timeout' => 12,
                    'temperature' => 0.8,
                    'topP' => 0.95,
                    'thinkingBudget' => 0,
                    'maxOutputTokens' => 400,
                ]
            );

            $out = trim(trim((string) ($result['reply'] ?? '')), "\"' \n\t");

            /*
             * إعادة الصياغة ممنوع تضيّع معلومة. في تجربة حقيقية رسالة
             * فيها النظامين والنِسب والمدد اتحوّلت لسطرين مفيهمش ولا
             * رقم - يعني العميل سأل تاني وخد إجابة أقل. لو أي رقم ضاع
             * أو اتزوّد، بنرمي الصياغة الجديدة ونبعت الأصلية.
             */
            if (($result['ok'] ?? false) && $out !== '' && $this->keepsSameNumbers($reply, $out)) {
                return $this->withEscapeHatch($out, $escapeHatch);
            }

            if (($result['ok'] ?? false) && $out !== '') {
                Log::warning('loop_guard_rewrite_dropped_facts', [
                    'conversation_id' => $conversation->id,
                    'original' => mb_substr($reply, 0, 200),
                    'rewrite' => mb_substr($out, 0, 200),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('loop guard rewrite failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->fallbackRewrite($reply, $escapeHatch);
    }

    /**
     * صياغة حتمية للحالة اللي الـ LLM بيفشل فيها. مش المفروض تكون
     * الطريق العادي، بس لازم تكون موجودة عشان أسوأ حاجة ممكنة تحصل هي
     * رسالة مختلفة - مش نفس الرسالة للمرة التالتة.
     */
    private function fallbackRewrite(string $reply, bool $escapeHatch): string
    {
        $core = trim($reply);

        if (! $escapeHatch) {
            /*
             * بنوصل هنا للأسئلة بس - الردود المعلوماتية بتتمسك قبل كده
             * في inspect() ومبتترجعش أصلاً. والمقدمة هنا مالهاش لازمة
             * غير مع سؤال حقيقي.
             */
            return "معلش يا فندم، يمكن سؤالي مكانش واضح.\n" . $core;
        }

        return $this->withEscapeHatch($core, true);
    }

    /**
     * الرد ده سؤال للعميل ولا معلومة؟ التفرقة دي هي اللي بتحدد نعمل إيه
     * لما يتكرر: السؤال بيتعاد بصياغة تانية، أما المعلومة فمبتتعادش.
     */
    private function isQuestion(string $reply): bool
    {
        $reply = rtrim(trim($reply));

        return str_ends_with($reply, '؟') || str_ends_with($reply, '?');
    }

    /**
     * باب الخروج بيتضاف من الكود مش من الـ LLM. جربنا نسيبه للموديل في
     * البرومبت فكان بيسقطه أحيانًا ويرجّع سطر واحد - والسطر ده بالظبط هو
     * اللي بيمنع العميل من إنه يفضل حابس في نفس السؤال.
     */
    private function withEscapeHatch(string $reply, bool $escapeHatch): string
    {
        $reply = trim($reply);

        if (! $escapeHatch || $reply === '') {
            return $reply;
        }

        if (str_contains($reply, 'عدّيها') || str_contains($reply, 'عديها')) {
            return $reply;
        }

        return $reply
            . "\n\nولو الحاجة دي مش متاحة معاك دلوقتي، قولي \"عدّيها\" ونكمل ونرجعلها بعدين،"
            . " أو قولي \"عايز أكلم حد\" وأحولك لزميل من الفريق.";
    }

    /**
     * نفس أرقام الرسالة الأصلية موجودة في الصياغة الجديدة، ومفيش رقم
     * جديد. الأرقام العربية بتتحوّل لإنجليزي والفواصل بتتشال قبل
     * المقارنة عشان "39,500" و"٣٩٥٠٠" يتحسبوا واحد.
     */
    private function keepsSameNumbers(string $original, string $rewrite): bool
    {
        $original = $this->numbersIn($original);
        $rewrite = $this->numbersIn($rewrite);

        return empty(array_diff($original, $rewrite)) && empty(array_diff($rewrite, $original));
    }

    /** @return array<int,string> */
    private function numbersIn(string $text): array
    {
        $text = str_replace(
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $text
        );

        $text = preg_replace('/(?<=\d)[,٬\x{066C}\s](?=\d{3}\b)/u', '', $text);

        preg_match_all('/\d+/u', (string) $text, $matches);

        return array_values(array_unique(array_map(
            fn (string $n) => ltrim($n, '0') ?: '0',
            $matches[0] ?? []
        )));
    }

    private function bumpStreak(WhatsappConversation $conversation): int
    {
        $context = is_array($conversation->context_payload) ? $conversation->context_payload : [];
        $streak = (int) ($context['loop_streak'] ?? 0) + 1;
        $context['loop_streak'] = $streak;

        $conversation->forceFill(['context_payload' => $context])->save();

        return $streak;
    }

    private function resetStreak(WhatsappConversation $conversation): void
    {
        $context = is_array($conversation->context_payload) ? $conversation->context_payload : [];

        if ((int) ($context['loop_streak'] ?? 0) === 0) {
            return;
        }

        $context['loop_streak'] = 0;

        $conversation->forceFill(['context_payload' => $context])->save();
    }
}
