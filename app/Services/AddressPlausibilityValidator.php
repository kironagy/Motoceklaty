<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AddressParser answers "which components are present". It deliberately
 * cannot answer "is any of this real": its last-resort branch treats
 * whatever text is left over as the street name, so "١٢ شارع ياوحشني
 * سلامات" parses into a perfectly well-formed address and the flow
 * happily stores a song lyric as the customer's home.
 *
 * This class answers the second question, and only the second question.
 * It is an LLM check on purpose - Egypt has thousands of real streets and
 * areas with names that look like jokes to any hardcoded list ("شارع
 * البط", "عزبة الوالدة", "كفر الشيخ حجازي"), so a whitelist would reject
 * far more real addresses than fake ones. The model is asked for
 * geographic recognition, not for taste.
 *
 * Three verdicts, and the middle one matters most:
 *   real     - recognisable, or plausibly a real small Egyptian locality
 *   unclear  - shaped like an address but not locatable; ask for a
 *              landmark / a bigger nearby area instead of rejecting it
 *   fake     - not an address at all (lyrics, joke, mashed letters, a
 *              sentence that answers a different question)
 *
 * "unclear" is the default whenever the model is hesitant, because the
 * cost of wrongly calling a real village fake is a customer who cannot
 * finish the application at all.
 */
class AddressPlausibilityValidator
{
    public const VERDICT_REAL = 'real';
    public const VERDICT_UNCLEAR = 'unclear';
    public const VERDICT_FAKE = 'fake';

    public function __construct(private readonly ?GeminiClient $gemini = null)
    {
    }

    /**
     * @param  'home_address'|'work_address'  $field
     * @param  array<string, mixed>  $components  AddressParser output, passed
     *         so the model judges the same decomposition the rest of the
     *         flow uses instead of re-splitting the text its own way.
     * @return array{
     *     verdict: string,
     *     confidence: float,
     *     reason: ?string,
     *     question: ?string,
     *     suspect_part: ?string,
     *     checked: bool
     * }
     */
    public function validate(string $text, string $field, array $components = []): array
    {
        $text = trim($text);

        if ($text === '') {
            return $this->outcome(self::VERDICT_UNCLEAR, 0.0, null, null, null, false);
        }

        /*
         * Cheap structural rejects first - a keyboard mash never needs to
         * cost a model call, and the model occasionally rationalises one
         * into "probably a village name".
         */
        if (($structural = $this->structuralVerdict($text, $field)) !== null) {
            return $structural;
        }

        $cacheKey = 'address_plausibility:' . $field . ':' . md5($text);

        try {
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable $e) {
            // Cache unavailable - just do the call.
        }

        $outcome = $this->askModel($text, $field, $components);

        try {
            Cache::put($cacheKey, $outcome, now()->addDays(7));
        } catch (\Throwable $e) {
            // Optional.
        }

        return $outcome;
    }

    /**
     * Things no model is needed for. Only unambiguous cases belong here -
     * anything requiring judgement about Egyptian place names goes to the
     * model.
     */
    private function structuralVerdict(string $text, string $field): ?array
    {
        $stripped = preg_replace('/[\s\p{P}]+/u', '', $text) ?? $text;

        // Same character four or more times in a row ("ااااا", "ككككك").
        if (preg_match('/(.)\1{3,}/u', $stripped)) {
            return $this->outcome(
                self::VERDICT_FAKE,
                1.0,
                'repeated_characters',
                $this->question($field, self::VERDICT_FAKE, null),
                null,
                true
            );
        }

        // Nothing but digits/punctuation - a bare "12" is not an address.
        if (preg_match('/^[\d\s\p{P}]+$/u', $text)) {
            return $this->outcome(
                self::VERDICT_UNCLEAR,
                1.0,
                'digits_only',
                $this->question($field, self::VERDICT_UNCLEAR, null),
                null,
                true
            );
        }

        return null;
    }

    private function askModel(string $text, string $field, array $components): array
    {
        $label = $field === 'work_address' ? 'عنوان الشغل' : 'عنوان السكن';

        $payload = [
            'address_text' => $text,
            'address_type' => $label,
            'parsed_components' => array_filter($components, fn ($value) => filled($value)),
        ];

        $prompt = <<<PROMPT
أنت بتراجع عنوان بعته عميل مصري في واتساب لطلب تقسيط موتوسيكل.

السؤال الوحيد: هل ده عنوان حقيقي في مصر ولا لأ؟

مهم جدًا قبل ما تحكم:
- مصر فيها آلاف الشوارع والقرى والعزب والكفور بأسماء غريبة وحقيقية جدًا
  (زي "عزبة الوالدة"، "كفر الشيخ حجازي"، "شارع البط"، "نجع الفقراء"،
  "منشية ناصر"، "أبو رجوان"). ممنوع ترفض عنوان لمجرد إن الاسم غريب عليك
  أو مش عارفه.
- كتير من الناس بيكتبوا العنوان عامية أو بإملاء غلط أو من غير المحافظة.
  ده مش تزوير، ده عنوان ناقص.
- الحكم بتاعك على *واقعية* العنوان بس، مش على اكتماله. عنوان حقيقي ناقص
  رقم العمارة يفضل "real".

الأحكام الثلاثة:
1. "real" - العنوان مكوّن من أسماء أماكن حقيقية في مصر أو أسماء ممكن جدًا
   تكون لمكان حقيقي صغير (قرية/عزبة/شارع محلي) حتى لو مش مشهور.
2. "unclear" - شكله عنوان بس فيه جزء مش قادر تتأكد إنه مكان (اسم مبهم جدًا
   أو حروف مش واضحة أو منطقة متكتبتش أصلاً). ده الحكم الافتراضي لو متردد.
3. "fake" - مش عنوان أصلاً: كلام أغنية أو جملة مشاعر (زي "شارع يا وحشني
   سلامات")، هزار، شتيمة، حروف عشوائية، أو جملة بترد على سؤال تاني
   (زي "مش فاكر" أو "هبعتهولك بعدين").

قاعدة الترجيح: لو مترددش بين real وfake، اختار unclear. رفض عنوان حقيقي
أسوأ بكتير من قبول عنوان مشكوك فيه، لأن العميل ساعتها مش هيقدر يكمّل طلبه
خالص.

كمان:
- suspect_part = الجزء اللي مريّبك بالظبط من النص (اسم الشارع مثلاً)، أو
  null لو مفيش.
- question = سؤال قصير جدًا بالعامية المصرية يخلي العميل يوضّح العنوان
  أكتر (مثلاً يذكر المنطقة/المحافظة أو علامة مميزة قريبة أو اسم الشارع
  زي ما هو معروف عند الناس). لازم يكون مؤدب ومايتهمش العميل بالكذب. سيبه
  null لو الحكم "real".

رجّع JSON بس من غير أي شرح:
{"verdict": "real|unclear|fake", "confidence": 0.0 لحد 1.0, "reason": "سبب مختصر بالعربي", "suspect_part": "...أو null", "question": "...أو null"}

العنوان:
PROMPT
            . "\n" . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        try {
            $response = ($this->gemini ?? app(GeminiClient::class))->generateText($prompt, null, [
                'temperature' => 0.0,
                'maxOutputTokens' => 350,
                'thinkingBudget' => 0,
            ]);

            if (! ($response['ok'] ?? false)) {
                return $this->unchecked();
            }

            $json = $this->decodeJson((string) ($response['reply'] ?? $response['text'] ?? ''));

            if (! is_array($json)) {
                return $this->unchecked();
            }

            $verdict = (string) ($json['verdict'] ?? '');

            if (! in_array($verdict, [self::VERDICT_REAL, self::VERDICT_UNCLEAR, self::VERDICT_FAKE], true)) {
                return $this->unchecked();
            }

            $suspectPart = is_string($json['suspect_part'] ?? null) && trim($json['suspect_part']) !== ''
                ? trim($json['suspect_part'])
                : null;

            $question = is_string($json['question'] ?? null) && trim($json['question']) !== ''
                ? trim($json['question'])
                : null;

            return $this->outcome(
                $verdict,
                max(0.0, min(1.0, (float) ($json['confidence'] ?? 0.5))),
                is_string($json['reason'] ?? null) ? $json['reason'] : null,
                $verdict === self::VERDICT_REAL ? null : ($question ?: $this->question($field, $verdict, $suspectPart)),
                $suspectPart,
                true
            );
        } catch (\Throwable $e) {
            Log::warning('Address plausibility check failed', ['message' => $e->getMessage()]);

            return $this->unchecked();
        }
    }

    /**
     * Fallback wording when the model gave a verdict but no usable
     * question (or when the structural layer fired, which never produces
     * one). Never accuses the customer of lying - the same message has to
     * work for someone who really does live on a street nobody has heard
     * of.
     */
    private function question(string $field, string $verdict, ?string $suspectPart): string
    {
        $label = $field === 'work_address' ? 'عنوان الشغل' : 'عنوان السكن';

        if ($verdict === self::VERDICT_FAKE) {
            return "معلش يا فندم، {$label} اللي وصلني مش واضح إنه عنوان. "
                . 'ابعتلي العنوان بالتفصيل: المنطقة والمحافظة، اسم الشارع، رقم العمارة والدور والشقة، وعلامة مميزة قريبة.';
        }

        $part = $suspectPart !== null ? " (\"{$suspectPart}\")" : '';

        return "محتاج أتأكد من {$label}{$part} يا فندم. "
            . 'ممكن تقولي المنطقة والمحافظة بالظبط، وعلامة مميزة قريبة منه (مسجد، مدرسة، محطة)؟';
    }

    /**
     * A check we could not run is NOT a failed check - the address passes
     * through untouched so an API outage can never block an application.
     */
    private function unchecked(): array
    {
        return $this->outcome(self::VERDICT_REAL, 0.0, 'not_checked', null, null, false);
    }

    private function outcome(
        string $verdict,
        float $confidence,
        ?string $reason,
        ?string $question,
        ?string $suspectPart,
        bool $checked
    ): array {
        return [
            'verdict' => $verdict,
            'confidence' => $confidence,
            'reason' => $reason,
            'question' => $question,
            'suspect_part' => $suspectPart,
            'checked' => $checked,
        ];
    }

    private function decodeJson(string $text): ?array
    {
        $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text)) ?? '';
        $text = preg_replace('/\s*```$/', '', $text) ?? '';

        if (preg_match('/\{.*\}/su', $text, $m)) {
            $text = $m[0];
        }

        $data = json_decode($text, true);

        return is_array($data) ? $data : null;
    }
}
