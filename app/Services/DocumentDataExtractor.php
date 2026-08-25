<?php

namespace App\Services;

use App\Support\FuzzyArabicMatcher;
use Illuminate\Support\Facades\Log;

class DocumentDataExtractor
{
    /**
     * Documents that are only worth anything if they belong to the person
     * applying. A salary slip, a pension statement, a driving licence or a
     * screenshot of somebody else's delivery account proves nothing about
     * this applicant - and sending a friend's paperwork is the single most
     * common way this flow gets gamed. Kept out of `activity_photo` (a
     * photo of a shop carries no name) and out of `id_card_back` (the back
     * of an Egyptian ID has no name on it).
     */
    private const NAME_BEARING_DOCUMENTS = [
        'id_card_front',
        'salary_slip',
        'pension_statement',
        'driver_license',
        'vehicle_license',
        'trips_screenshot',
        'bank_statement',
    ];

    /** Documents that are worthless once expired. */
    private const EXPIRY_BEARING_DOCUMENTS = [
        'driver_license',
        'vehicle_license',
    ];

    private const DOCUMENT_LABELS = [
        'id_card_front' => 'البطاقة',
        'salary_slip' => 'مفردات المرتب',
        'pension_statement' => 'بيان المعاش',
        'driver_license' => 'رخصة القيادة',
        'vehicle_license' => 'رخصة المركبة',
        'trips_screenshot' => 'السكرين بتاع التطبيق',
        'bank_statement' => 'كشف الحساب',
    ];

    public function __construct(private readonly ?FuzzyArabicMatcher $fuzzy = null)
    {
    }

    /**
     * Extract structured fields from OCR text and validate them against
     * the business rule text pulled from ai_memories (no hardcoded rules).
     *
     * @return array{ok:bool,valid:bool,fields:array,violation_message:?string}
     */
    public function extract(
        string $ocrText,
        string $documentType,
        string $rulesText,
        array $knownContext = []
    ): array {
        $ocrText = trim($ocrText);

        if ($ocrText === '') {
            return [
                'ok' => false,
                'valid' => false,
                'fields' => [],
                'violation_message' => 'مقدرتش أقرا بيانات من الصورة، ممكن تبعتها تاني بجودة أوضح؟',
            ];
        }

        $payload = [
            'document_type' => $documentType,
            'ocr_text' => $ocrText,
            'known_context' => $knownContext,
            'today' => now()->format('Y-m-d'),
        ];

        $prompt = <<<PROMPT
أنت نظام استخراج وتحقق بيانات مستندات لمعرض موتوسيكلات.

ممنوع ترد على العميل مباشرة. رجّع JSON فقط بدون أي شرح.

استخرج من نص الـ OCR كل البيانات المتاحة (اسم، رقم قومي، تاريخ ميلاد،
مرتب، تاريخ تحرير المستند، تاريخ تعيين، تاريخ إصدار سجل تجاري/بطاقة
ضريبية...إلخ حسب نوع المستند).

بعد الاستخراج، طبّق القواعد النصية دي بالظبط (القواعد جاية من لوحة تحكم
العميل ومحتواها هو المرجع الوحيد، ما تخترعش قواعد إضافية):

--- القواعد ---
{$rulesText}
--- نهاية القواعد ---

مهم جدًا:
- التحقق (valid) بيكون بس عن حاجات موجودة أو ممكن تتأكد منها من نص
  المستند نفسه (وضوح البيانات، الرقم القومي كامل، تاريخ منطقي، مطابقة
  القواعد المذكورة فوق).
- ممنوع تطلب في violation_message أي بيانات مالهاش علاقة بالمستند ده
  نفسه (زي رقم تليفون إضافي، عناوين تانية، تاريخ قروض سابقة) حتى لو
  مذكورة في مكان تاني — دي بتتجمع في خطوات تانية من المحادثة مش هنا.
- لو المستند واضح ومطابق لنوعه ومفيهوش مخالفة من القواعد، خليه valid=true
  وviolation_message=null، حتى لو فيه بيانات تانية للعميل لسه ناقصة في
  الطلب ككل.

عن الاسم (مهم جدًا):
- known_context.expected_name هو اسم مقدّم الطلب زي ما هو مكتوب في
  المحادثة. المستند لازم يكون *باسمه هو*، مش باسم حد تاني.
- استخرج الاسم المكتوب في المستند نفسه في الحقل name_on_document زي ما هو
  حرفيًا. لو المستند مفيهوش اسم خالص سيبه null.
- name_matches: true لو الاسم المكتوب في المستند هو نفس شخص
  expected_name، false لو شخص تاني واضح، وnull لو مفيش اسم في المستند أو
  الـ OCR قراه مقطّع ومش قادر تحكم.
- اختلاف الإملاء أو التشكيل أو ترتيب/عدد الأسماء (ثلاثي في المستند ورباعي
  في المحادثة، أو العكس) *مش* اختلاف شخص. سكرين تطبيق ممكن يكون فيه
  الاسم الأول بس أو nickname - ده برضو مش اختلاف شخص.
- خلي name_matches = false بس لما يكون واضح إنهم اتنين مختلفين فعلاً.

عن الصلاحية:
- لو المستند رخصة (قيادة أو مركبة)، استخرج expiry_date بصيغة YYYY-MM-DD
  في الحقول، وحدد is_expired = true لو تاريخ انتهائها قبل تاريخ النهاردة
  المذكور تحت. لو التاريخ مش مقروء سيب expiry_date = null وis_expired =
  null، ومتفترضش إنها سارية.

التاريخ النهارده: {$payload['today']}

رجّع JSON بهذا الشكل فقط:
{
  "fields": { "أي حقول اتقرت من المستند": "قيمتها" },
  "name_on_document": "الاسم زي ما هو في المستند أو null",
  "name_matches": true أو false أو null,
  "valid": true أو false,
  "violation_message": "شرح بالعربي المصري لو فيه مخالفة، وإلا null"
}

بيانات المستند:
PROMPT
            . "\n" . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        try {
            $result = app(GeminiClient::class)->generateText($prompt, null, [
                'temperature' => 0.05,
                'maxOutputTokens' => 800,
            ]);

            if (! ($result['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'valid' => false,
                    'fields' => [],
                    'violation_message' => 'حصلت مشكلة أثناء مراجعة المستند، جرب تبعته تاني.',
                ];
            }

            $json = $this->extractJson(trim((string) ($result['reply'] ?? $result['text'] ?? '')));

            if (! is_array($json)) {
                return [
                    'ok' => false,
                    'valid' => false,
                    'fields' => [],
                    'violation_message' => 'حصلت مشكلة أثناء مراجعة المستند، جرب تبعته تاني.',
                ];
            }

            $fields = is_array($json['fields'] ?? null) ? $json['fields'] : [];

            $nameOnDocument = is_string($json['name_on_document'] ?? null) && trim($json['name_on_document']) !== ''
                ? trim($json['name_on_document'])
                : null;

            if ($nameOnDocument !== null) {
                $fields['name_on_document'] = $nameOnDocument;
            }

            $outcome = [
                'ok' => true,
                'valid' => (bool) ($json['valid'] ?? false),
                'fields' => $fields,
                'violation_message' => $json['violation_message'] ?? null,
                'name_on_document' => $nameOnDocument,
                'name_matches' => array_key_exists('name_matches', $json) && $json['name_matches'] !== null
                    ? (bool) $json['name_matches']
                    : null,
            ];

            /*
             * القواعد اللي مش سايبينها لتقدير الموديل: "باسمه" و"سارية".
             * الموديل ممكن يرجّع valid=true وهو شايف اسم تاني خالص في
             * المستند لأن القواعد النصية الجاية من الميموري مذكرتش الاسم -
             * فبنطبقها هنا فوق رده، مش جواه.
             */
            return $this->applyOwnershipChecks($outcome, $documentType, $knownContext);
        } catch (\Throwable $e) {
            Log::error('DocumentDataExtractor failed', ['message' => $e->getMessage()]);

            return [
                'ok' => false,
                'valid' => false,
                'fields' => [],
                'violation_message' => 'حصلت مشكلة أثناء مراجعة المستند، جرب تبعته تاني.',
            ];
        }
    }

    /**
     * The two rules that are never delegated to the model's own `valid`
     * verdict: the document has to be in the applicant's name, and a
     * licence has to still be valid.
     *
     * The name check runs twice on purpose. The model's `name_matches` is
     * the judgement call (it understands that "محمد أحمد" on a payslip and
     * "محمد أحمد إبراهيم علي" in the chat are one person). The fuzzy
     * string check underneath is the floor: if the model said "same
     * person" while not one single name part appears in the document, that
     * is a model being agreeable, not a match.
     */
    private function applyOwnershipChecks(array $outcome, string $documentType, array $knownContext): array
    {
        $label = self::DOCUMENT_LABELS[$documentType] ?? 'المستند';

        if (in_array($documentType, self::EXPIRY_BEARING_DOCUMENTS, true)) {
            $expired = $outcome['fields']['is_expired'] ?? null;
            $expiryDate = $outcome['fields']['expiry_date'] ?? null;

            if ($expired === true || $expired === 'true' || $expired === 1) {
                return $this->rejected(
                    $outcome,
                    "{$label} اللي بعتها منتهية" . ($expiryDate ? " (انتهت في {$expiryDate})" : '')
                        . '. محتاجين رخصة سارية باسم حضرتك عشان نقدر نكمّل الطلب.'
                );
            }
        }

        if (! in_array($documentType, self::NAME_BEARING_DOCUMENTS, true)) {
            return $outcome;
        }

        $expectedName = trim((string) ($knownContext['expected_name'] ?? ''));

        // No name to compare against yet - nothing to enforce.
        if ($expectedName === '') {
            return $outcome;
        }

        if ($outcome['name_matches'] === false) {
            return $this->rejected(
                $outcome,
                "{$label} دي مكتوب فيها اسم تاني"
                    . ($outcome['name_on_document'] ? " (\"{$outcome['name_on_document']}\")" : '')
                    . ", مش اسم حضرتك ({$expectedName}). لازم كل المستندات تكون باسم مقدّم الطلب نفسه."
            );
        }

        $nameOnDocument = $outcome['name_on_document'];

        /*
         * Model said "matches" (or could not tell) but there is a readable
         * name on the document - verify at least one name part actually
         * survives into it before believing that.
         */
        if ($nameOnDocument !== null && ! $this->sharesNamePart($expectedName, $nameOnDocument)) {
            return $this->rejected(
                $outcome,
                "الاسم المكتوب في {$label} (\"{$nameOnDocument}\") مش مطابق لاسم حضرتك ({$expectedName})."
                    . ' ابعتلي المستند اللي باسمك إنت من فضلك.'
            );
        }

        return $outcome;
    }

    /**
     * True when the two names share at least one meaningful part, with the
     * same Arabic folding and edit-distance-1 tolerance used everywhere
     * else in this codebase - OCR routinely drops or mangles one letter,
     * and that must not read as a different person.
     */
    private function sharesNamePart(string $expected, string $onDocument): bool
    {
        $fuzzy = $this->fuzzy ?? new FuzzyArabicMatcher();

        $haystack = $this->foldArabic($onDocument);

        foreach (preg_split('/\s+/u', $this->foldArabic($expected), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            // Single/double-letter fragments ("ال", "بن") match anything -
            // they are not evidence of a shared identity.
            if (mb_strlen($part) < 3) {
                continue;
            }

            if ($fuzzy->containsFuzzyPhrase($haystack, $part)) {
                return true;
            }
        }

        return false;
    }

    private function foldArabic(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace('ى', 'ي', $text);
        $text = preg_replace('/[\x{0617}-\x{061A}\x{064B}-\x{0652}]/u', '', $text) ?? $text;
        $text = preg_replace('/[^\p{Arabic}a-z0-9\s]/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function rejected(array $outcome, string $message): array
    {
        $outcome['valid'] = false;
        $outcome['violation_message'] = $message;

        return $outcome;
    }

    private function extractJson(string $text): ?array
    {
        $text = preg_replace('/^```json\s*/i', '', $text);
        $text = preg_replace('/^```\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', trim((string) $text));

        if (preg_match('/\{.*\}/su', (string) $text, $m)) {
            $text = $m[0];
        }

        $data = json_decode((string) $text, true);

        return is_array($data) ? $data : null;
    }
}
