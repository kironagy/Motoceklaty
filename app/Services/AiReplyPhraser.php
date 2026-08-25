<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Plan task 2.4: let the model write the sentence, never the numbers.
 *
 * Every money reply in the router is assembled from database values into a
 * fixed template, which is why the bot reads like a form letter. This class
 * hands that finished, correct sentence to the model and asks only for a
 * rewording - then refuses the rewording unless it is provably the same
 * facts: no digit may appear that was not already in the deterministic text,
 * nothing the caller marked as required may be dropped, and the length has
 * to stay in the same ballpark. Anything else and the deterministic reply
 * goes out unchanged, so the worst case of this feature is today's behaviour.
 */
class AiReplyPhraser
{
    public function phrase(string $deterministicReply, array $options = []): string
    {
        $deterministicReply = trim($deterministicReply);

        if ($deterministicReply === '' || ! config('gemini.ai_phrasing.enabled', true)) {
            return $deterministicReply;
        }

        if (mb_strlen($deterministicReply) > (int) config('gemini.ai_phrasing.max_chars', 1200)) {
            return $deterministicReply;
        }

        /*
         * A one-line reply ("تمام، ابعتلي اسمك") already reads like a person
         * wrote it, so rewording it buys nothing and costs a full extra
         * round trip on the reasoning model - on top of the planner call the
         * same message already paid for. Short replies now go out as-is.
         */
        if (mb_strlen($deterministicReply) < (int) config('gemini.ai_phrasing.min_chars', 80)) {
            return $deterministicReply;
        }

        try {
            $result = app(GeminiClient::class)->generateText(
                prompt: $this->prompt($deterministicReply, $options),
                preferredModelCode: config('gemini.models.reasoning'),
                options: [
                    'timeout' => 12,
                    'temperature' => 0.75,
                    'topP' => 0.95,
                    // The facts are already resolved - this call only words
                    // them, so thinking would just eat the output budget.
                    'thinkingBudget' => 0,
                    'maxOutputTokens' => 700,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('ai_phrasing_failed', ['error' => $e->getMessage()]);

            return $deterministicReply;
        }

        if (! ($result['ok'] ?? false)) {
            return $deterministicReply;
        }

        $candidate = $this->clean((string) ($result['reply'] ?? ''));
        $rejection = $this->rejectionReason($candidate, $deterministicReply, $options);

        if ($rejection !== null) {
            Log::warning('ai_phrasing_rejected', [
                'reason' => $rejection,
                'context' => $options['context'] ?? null,
                'candidate' => mb_substr($candidate, 0, 300),
                'deterministic' => mb_substr($deterministicReply, 0, 300),
            ]);

            return $deterministicReply;
        }

        return $candidate;
    }

    /**
     * Returns null when the candidate is safe to send, or a short reason
     * string when it must be discarded. Public so it can be unit tested
     * without touching Gemini.
     */
    public function rejectionReason(string $candidate, string $deterministicReply, array $options = []): ?string
    {
        $candidate = trim($candidate);

        if ($candidate === '') {
            return 'empty';
        }

        $allowed = $this->numbersIn($deterministicReply);
        $used = $this->numbersIn($candidate);
        $invented = array_diff($used, $allowed);

        if (! empty($invented)) {
            return 'invented_numbers:' . implode(',', array_slice($invented, 0, 5));
        }

        $missing = array_diff($allowed, $used);

        if (! empty($missing)) {
            return 'dropped_numbers:' . implode(',', array_slice($missing, 0, 5));
        }

        foreach ($this->requiredFragments($deterministicReply, $options) as $fragment) {
            if (! str_contains($candidate, $fragment)) {
                return 'dropped_fragment:' . mb_substr($fragment, 0, 40);
            }
        }

        $ratio = mb_strlen($candidate) / max(1, mb_strlen($deterministicReply));

        if ($ratio < 0.5 || $ratio > 2.5) {
            return 'length_ratio:' . round($ratio, 2);
        }

        return null;
    }

    /**
     * Machine names and any caller-supplied must-keep strings. Bullet lines
     * ("- دايو ٤: 39,500 جنيه") are the one piece of structure worth
     * enforcing: the model likes to melt them into prose, which loses the
     * per-model mapping even though every number survives.
     */
    private function requiredFragments(string $deterministicReply, array $options): array
    {
        /*
         * Only fragments the deterministic text actually contains can be
         * required - a caller passing a machine's full display name
         * ("هوجان هوجن ٤ استيراد فرز تاني") when the template wrote a
         * shorter form would otherwise reject every rewording forever.
         */
        $fragments = array_values(array_filter(
            array_map('trim', (array) ($options['must_keep'] ?? [])),
            fn (string $fragment) => $fragment !== '' && str_contains($deterministicReply, $fragment)
        ));

        foreach (preg_split('/\R/u', $deterministicReply) ?: [] as $line) {
            $line = trim($line);

            if (str_starts_with($line, '-') && mb_strlen($line) > 2) {
                $fragments[] = $line;
            }
        }

        return array_unique($fragments);
    }

    private function prompt(string $deterministicReply, array $options): string
    {
        $context = trim((string) ($options['context'] ?? ''));
        $contextLine = $context !== '' ? "الموضوع: {$context}\n" : '';

        return <<<PROMPT
انت بايع في معرض ماكينات في مصر، بترد على واتساب بالعامية المصرية.

تحت دي رسالة صحيحة ١٠٠٪ جاهزة للإرسال. مهمتك الوحيدة: تعيد صياغتها بأسلوب بشري طبيعي ودود - كإن بني آدم كتبها، مش قالب.

قواعد ملزمة:
- ممنوع تضيف أي رقم مش موجود في الرسالة الأصلية، وممنوع تشيل أي رقم منها. الأرقام تتنقل زي ما هي بالحرف.
- ممنوع تضيف معلومة جديدة (مواعيد، عروض، شروط، أماكن) - المعلومات هي هي بالظبط.
- لو فيه سطور بتبدأ بـ "-" (قايمة موديلات وأسعارها) سيبها سطور منفصلة بنفس النص بالظبط، وغيّر الكلام اللي حواليها بس.
- لو فيه سؤال في آخر الرسالة، سيب سؤال في الآخر (ممكن بصياغة تانية).
- الطول قريب من الأصل. من غير emoji كتير، من غير markdown، من غير أقواس شرح.
- رد بالرسالة الجديدة بس، من غير أي مقدمة.

{$contextLine}الرسالة الأصلية:
{$deterministicReply}
PROMPT;
    }

    private function clean(string $reply): string
    {
        $reply = trim($reply);
        $reply = preg_replace('/^```[a-zA-Z]*\s*/u', '', $reply);
        $reply = preg_replace('/\s*```$/u', '', $reply);
        $reply = trim($reply, "\"' \n\t");

        return trim($reply);
    }

    /**
     * All numeric values in a text, normalized so ١٢ / 12 and 39,500 /
     * 39500 compare equal.
     */
    private function numbersIn(string $text): array
    {
        $text = str_replace(
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $text
        );

        // Thousands separators (and the space some models use) only when they
        // actually sit between digits, so "12 شهر" never merges with a
        // following number.
        $text = preg_replace('/(?<=\d)[,٬\x{066C}\s](?=\d{3}\b)/u', '', $text);

        preg_match_all('/\d+(?:\.\d+)?/u', $text, $matches);

        $numbers = array_map(
            function (string $number): string {
                // Trim only fractional zeros - rtrim on an integer would turn
                // 39500 into 395. Leading zeros go via ltrim.
                if (str_contains($number, '.')) {
                    $number = rtrim(rtrim($number, '0'), '.');
                }

                return ltrim($number, '0') ?: '0';
            },
            $matches[0] ?? []
        );

        return array_values(array_unique($numbers));
    }
}
