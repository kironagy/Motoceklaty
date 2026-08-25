<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * "الاسم بالكامل" has to actually mean the full name that appears on the
 * national ID. Before this, any non-empty string satisfied the field, so
 * "كيرلس" or "أحمد محمد" closed it and the request reached staff with a
 * name that cannot be matched against the ID card.
 *
 * Two layers, in this order:
 *
 *  1. Structure (free, deterministic): digits, latin text, absurd length,
 *     and - the main one - how many name parts there are. An Egyptian
 *     legal name is at least triple (اسم + أب + جد); two parts is the
 *     single most common way customers under-answer this question.
 *  2. Plausibility (LLM): are these parts actually *personal name* words,
 *     or did the customer type a job, a place, a greeting, or mashed
 *     letters? Deliberately not a whitelist of known Egyptian first names -
 *     that would reject real, rare, Coptic, Nubian, and Bedouin names,
 *     which is worse than the problem it solves. The model is asked to
 *     reject only what is clearly not a name at all.
 *
 * Layer 2 is cached per exact name string: the same customer re-sending
 * the same name across turns must not spend a call every time.
 */
class ApplicantNameValidator
{
    /** Minimum parts for a name to count as "بالكامل" here. */
    public const MIN_PARTS = 3;

    /**
     * Words customers routinely put in front of the name itself; they are
     * not name parts and must not be counted as one, otherwise "اسمي أحمد
     * محمد" would look like a valid triple name.
     */
    private const LEAD_WORDS = [
        'اسمي', 'إسمي', 'اسمى', 'الاسم', 'الإسم', 'اسم', 'انا', 'أنا',
        'ana', 'name', 'my name is',
    ];

    /**
     * Connectors that are part of a real name but are not themselves a
     * name part ("عبد" is handled separately - it is half of a compound
     * first name, not a connector).
     */
    private const PARTICLES = ['بن', 'ابن', 'ال', 'أبو', 'ابو', 'عبد', 'ام', 'أم'];

    public function __construct(private readonly ?GeminiClient $gemini = null)
    {
    }

    /**
     * @return array{
     *     status: 'ok'|'incomplete'|'invalid',
     *     name: string,
     *     parts: array<int, string>,
     *     message: ?string,
     *     reason: ?string
     * }
     */
    public function validate(?string $raw): array
    {
        /*
         * The digit test runs on the ORIGINAL string, not on cleanup()'s
         * output - cleanup() strips digits along with the other noise, so
         * checking afterwards can never fire, and "احمد 123 محمد" would
         * quietly become a valid-looking triple name.
         */
        $hasDigits = preg_match('/[\d٠-٩۰-۹]/u', (string) $raw) === 1;

        $name = $this->cleanup((string) $raw);

        if ($name === '') {
            return $this->result('invalid', $name, [], 'empty', 'محتاج اسم حضرتك بالكامل زي ما هو مكتوب في البطاقة.');
        }

        if ($hasDigits) {
            return $this->result(
                'invalid',
                $name,
                [],
                'contains_digits',
                'الاسم اللي بعته فيه أرقام. ابعتلي اسم حضرتك بالكامل زي ما هو مكتوب في البطاقة من غير أرقام.'
            );
        }

        $parts = $this->parts($name);

        /*
         * A structurally short name is answered without spending a model
         * call - the question we need to ask is already known, and the
         * model cannot change the answer ("أحمد محمد" is a real name, it
         * is just not the *full* name).
         */
        if (count($parts) < self::MIN_PARTS) {
            return $this->result(
                'incomplete',
                $name,
                $parts,
                'too_few_parts',
                count($parts) <= 1
                    ? 'ده اسم حضرتك الأول بس. محتاج الاسم بالكامل زي ما هو مكتوب في البطاقة (رباعي لو ينفع، ثلاثي على الأقل).'
                    : 'محتاج الاسم بالكامل زي ما هو مكتوب في البطاقة - يعني اسم حضرتك واسم والدك وجدك (رباعي لو ينفع)، مش اسم ثنائي.'
            );
        }

        $plausibility = $this->plausibility($name);

        if (($plausibility['is_name'] ?? true) === false) {
            return $this->result(
                'invalid',
                $name,
                $parts,
                'not_a_name',
                trim((string) ($plausibility['question'] ?? ''))
                    ?: 'مش متأكد إن ده اسم حضرتك بالكامل. ابعتلي الاسم زي ما هو مكتوب في البطاقة من فضلك.'
            );
        }

        return $this->result('ok', $name, $parts, null, null);
    }

    /**
     * Strips lead-in words and normalizes whitespace/punctuation, so the
     * stored value is the name itself and the part count is honest.
     */
    public function cleanup(string $raw): string
    {
        $name = trim(preg_replace('/[^\p{Arabic}\p{L}\s\'\-]+/u', ' ', $raw) ?? '');
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ($name === '') {
            return '';
        }

        $lower = mb_strtolower($name);

        foreach (self::LEAD_WORDS as $lead) {
            if (mb_strpos($lower, $lead . ' ') === 0) {
                $name = trim(mb_substr($name, mb_strlen($lead) + 1));
                $lower = mb_strtolower($name);
            }
        }

        return $name;
    }

    /**
     * Name parts, with compound-name particles folded onto the word that
     * follows them: "عبد الرحمن" is ONE part, not two, and "أبو زيد" the
     * same - counting them separately would let a two-part name like
     * "عبد الرحمن محمد" pass as triple.
     *
     * @return array<int, string>
     */
    public function parts(string $name): array
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = [];
        $pending = '';

        foreach ($words as $word) {
            if (in_array($word, self::PARTICLES, true)) {
                $pending = $pending === '' ? $word : $pending . ' ' . $word;

                continue;
            }

            $parts[] = $pending === '' ? $word : $pending . ' ' . $word;
            $pending = '';
        }

        if ($pending !== '') {
            $parts[] = $pending;
        }

        return $parts;
    }

    /**
     * @return array{is_name: bool, question: ?string}
     */
    private function plausibility(string $name): array
    {
        $cacheKey = 'applicant_name_plausibility:' . md5($name);

        try {
            $cached = Cache::get($cacheKey);
        } catch (\Throwable $e) {
            $cached = null;
        }

        if (is_array($cached)) {
            return $cached;
        }

        $prompt = <<<PROMPT
أنت بتراجع اسم عميل مصري كتبه في محادثة واتساب لطلب تقسيط.

مهمتك سؤال واحد بس: هل النص ده اسم شخص حقيقي، ولا حاجة تانية خالص؟

اقبل (is_name = true):
- أي اسم عربي أو مصري أو قبطي أو نوبي أو بدوي أو أجنبي مكتوب بالعربي،
  حتى لو نادر جدًا أو حضرتك مش شايفه كتير. الأسماء المصرية فيها تنوع
  رهيب وممنوع ترفض اسم لمجرد إنه غريب عليك.
- الأسماء المركبة (عبد الرحمن، أبو المجد، نور الدين، بشرى...).
- اسم مكتوب من غير تشكيل أو بإملاء عامي بسيط.
- اسم فيه لقب عائلة أو نسبة (الشرقاوي، الصعيدي، عبد الله).

ارفض (is_name = false) بس لو النص واضح إنه:
- حروف عشوائية أو ضرب كيبورد (مثال: "سسسسس"، "asdasd"، "هخهخهخ").
- جملة أو رد مش اسم (مثال: "مش فاكر"، "هبعتهولك بعدين"، "ايوه تمام").
- وظيفة أو مكان أو اسم منتج بدل الاسم (مثال: "سواق توك توك"، "المعادي").
- شتيمة أو هزار واضح أو اسم شخصية كرتونية/مشهورة بشكل هزار.

لو مترددش، اعتبره اسم حقيقي (is_name = true) - رفض اسم حقيقي أسوأ
بكتير من قبول اسم مشكوك فيه.

رجّع JSON بس، من غير أي شرح:
{"is_name": true أو false, "question": "لو false، سؤال قصير بالعامية المصرية يطلب منه الاسم الصح زي ما هو في البطاقة"}

الاسم:
PROMPT
            . "\n" . json_encode(['name' => $name], JSON_UNESCAPED_UNICODE);

        $result = ['is_name' => true, 'question' => null];

        try {
            $response = ($this->gemini ?? app(GeminiClient::class))->generateText($prompt, null, [
                'temperature' => 0.0,
                'maxOutputTokens' => 200,
                'thinkingBudget' => 0,
            ]);

            if ($response['ok'] ?? false) {
                $json = $this->decodeJson((string) ($response['reply'] ?? $response['text'] ?? ''));

                if (is_array($json) && array_key_exists('is_name', $json)) {
                    $result = [
                        'is_name' => (bool) $json['is_name'],
                        'question' => is_string($json['question'] ?? null) ? $json['question'] : null,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Applicant name plausibility check failed', ['message' => $e->getMessage()]);
        }

        /*
         * On any failure the cached value is the permissive one - a model
         * outage must never turn into "your name is rejected".
         */
        try {
            Cache::put($cacheKey, $result, now()->addDays(7));
        } catch (\Throwable $e) {
            // Cache is an optimisation here, never a requirement.
        }

        return $result;
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

    private function result(string $status, string $name, array $parts, ?string $reason, ?string $message): array
    {
        return [
            'status' => $status,
            'name' => $name,
            'parts' => $parts,
            'reason' => $reason,
            'message' => $message,
        ];
    }
}
