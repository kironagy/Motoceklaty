<?php

namespace App\Services;

use App\Support\EgyptianNationalId;
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
        'work_app_screens',
        'bank_statement',
    ];

    /**
     * المستندات اللي الرقم القومي مكتوب فيها، فبيتقارن بالرقم اللي
     * العميل كتبه في المحادثة.
     */
    private const ID_BEARING_DOCUMENTS = [
        'id_card_front',
        'id_card_back',
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
        'work_app_screens' => 'سكرينات تطبيق الشغل',
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
        array $knownContext = [],
        ?array $image = null
    ): array {
        /*
         * البطاقة المصرية مطبوع عليها الأرقام بالهندي (٢٩٤...) والعميل
         * بيكتب رقمه في المحادثة بالعربي (294...). من غير التوحيد ده
         * المقارنة كانت متسايبة بالكامل للموديل، وهو بيشوف نصين مختلفين
         * شكلًا فيقول "الرقم القومي في المستند مش مطابق للمسجل في الطلب"
         * لواحد رقمه مظبوط - فيقعد يكتب الرقم صح والرد يفضل "غلط".
         */
        $ocrText = trim($this->normalizeDigits($ocrText));

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

عن الرقم القومي:
- أرقام نص الـ OCR اتحولت للأرقام العربية (0-9) قبل ما توصلك، وكذلك
  known_context.expected_national_id. قارن رقم بـرقم.
- لو نفس الـ 14 رقم موجودين في نص المستند (حتى لو متفرقين بمسافات أو على
  أكتر من سطر) يبقى مطابق - ممنوع ترفضه بسبب المسافات أو شكل الأرقام.
- ارجع الرقم اللي قريته في fields.national_id.

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

            return $this->applyOwnershipChecks($outcome, $documentType, $knownContext, $ocrText, $image);
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
     * الفحوصات الحتمية اللي بتتطبق فوق رد الموديل، بترتيبها.
     *
     * 1) الصفحة المطلوبة: سكرين الدخل باسم العميل هيعدّي كل فحوصات
     *    الملكية وهو أصلاً مش الصفحة اللي طلبناها، فده بييجي الأول.
     * 2) الملكية: الاسم والصلاحية والرقم القومي - دي مش سايبينها لتقدير
     *    الموديل، لا في اتجاه القبول ولا في اتجاه الرفض.
     *
     * الباج اللي بيصلحه الترتيب ده: كان فيه `return` بعد فحص الصفحة لو
     * الرد جه valid=false، فأي رفض من الموديل كان بيخرج من الدالة قبل ما
     * فحوصات الملكية تشتغل خالص - يعني حارس الرقم القومي (اللي بيلغي
     * الرفض لما الرقم يكون فعلاً في البطاقة) عمره ما كان بيتنادى في
     * الإنتاج. الرجوع المبكر بقى مقصور على رفض *فحص الصفحة نفسه*.
     */
    /**
     * البيانات المطلوبة من سكرينات تطبيق الشغل - مش صفحات بعينها.
     *
     * المطلوب تلات معلومات، مش تلات صور:
     *  1) تاريخ التعيين/الانضمام للتطبيق.
     *  2) اسم صاحب الحساب (عشان نتأكد إن الحساب بتاعه هو).
     *  3) دخل الشهور الأخيرة (عشان نتأكد إنه شغال فعلاً والحساب مش فاضي).
     *
     * لو سكرين واحد فيه التلاتة، خلاص. ولو متفرقين على كذا سكرين،
     * بيتجمعوا مع بعض. الاستخراج بيبص على الصورة نفسها مع نص الـ OCR
     * لأن السكرينات فيها أرقام وتواريخ الـ OCR بيقطّعها.
     *
     * @return array{ok:bool,facts:array}
     */
    public function extractWorkAppFacts(string $ocrText, ?array $image, array $knownContext = []): array
    {
        $ocrText = trim($this->normalizeDigits($ocrText));
        $expectedName = trim((string) ($knownContext['expected_name'] ?? ''));
        $today = now()->format('Y-m-d');

        $prompt = <<<PROMPT
دي صورة سكرين من تطبيق شغل توصيل (طلبات/أوبر/مرسول/أي تطبيق تاني).

المطلوب منك تستخرج - من الصورة نفسها ومن نص الـ OCR تحت - البيانات دي،
وأي حاجة مش ظاهرة في السكرين ده سيبها null. ممنوع تخمّن أو تكمّل من عندك.

1) hiring_date: تاريخ التعيين/الانضمام للتطبيق (زي "تاريخ الانضمام" أو
   "إنضم يوليو 2026" أو "5 من الشهور منذ انضمامك"). لو مكتوب مدة بالشهور
   بدل تاريخ، رجّعها زي ما هي في hiring_date_text.
2) account_name: اسم صاحب الحساب في التطبيق نفسه، زي ما هو مكتوب (عربي أو
   إنجليزي). **مهم**: الإشعارات والبانرات اللي بتظهر فوق الشاشة من تطبيقات
   تانية (إشعار فيسبوك أو واتساب مثلاً) فيها أسامي ناس تانية - دي مش اسم
   صاحب الحساب. لو الاسم الوحيد الظاهر جاي من إشعار، سيب account_name = null.
3) income_periods: كل فترة دخل ظاهرة، كل واحدة
   {"label": "الفترة زي ما هي مكتوبة", "month": "YYYY-MM لو تقدر تحددها", "amount": رقم}.
   المبالغ اليومية أو الأسبوعية أو الشهرية كلها تتحسب.
4) deliveries_count: عدد الطلبات/التوصيلات/المشاوير لو ظاهر.
5) account_active: true لو السكرين بيبيّن شغل فعلي (مبالغ أو طلبات
   أكبر من صفر)، false لو الحساب فاضي/مفيش أي دخل، null لو مش باين.

التاريخ النهارده: {$today}
اسم مقدّم الطلب زي ما هو في المحادثة: {$expectedName}

رجّع JSON بس:
{"app_name": "اسم التطبيق أو null", "hiring_date": "YYYY-MM-DD أو YYYY-MM أو null",
 "hiring_date_text": "النص زي ما هو أو null", "account_name": "الاسم أو null",
 "income_periods": [], "deliveries_count": رقم أو null, "account_active": true/false/null}

نص الـ OCR:
{$ocrText}
PROMPT;

        try {
            $options = [
                'temperature' => 0.05,
                'maxOutputTokens' => 700,
                'responseMimeType' => 'application/json',
            ];

            $binary = is_readable((string) ($image['path'] ?? '')) ? @file_get_contents($image['path']) : false;

            if ($binary !== false && $binary !== '') {
                $options['image_base64'] = base64_encode($binary);
                $options['image_mime'] = $image['mime'] ?? 'image/jpeg';
            }

            $result = app(GeminiClient::class)->generateText($prompt, 'gemini-3.1-flash-lite', $options);

            if (! ($result['ok'] ?? false)) {
                return ['ok' => false, 'facts' => []];
            }

            $json = $this->extractJson(trim((string) ($result['reply'] ?? $result['text'] ?? '')));

            if (! is_array($json)) {
                return ['ok' => false, 'facts' => []];
            }

            return ['ok' => true, 'facts' => $json];
        } catch (\Throwable $e) {
            Log::error('work app facts extraction failed', ['message' => $e->getMessage()]);

            return ['ok' => false, 'facts' => []];
        }
    }

    private function applyOwnershipChecks(
        array $outcome,
        string $documentType,
        array $knownContext,
        string $ocrText = '',
        ?array $image = null
    ): array {
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
            return $this->checkNationalId($outcome, $documentType, $knownContext, $ocrText, $image);
        }

        $expectedName = trim((string) ($knownContext['expected_name'] ?? ''));

        // No name to compare against yet - nothing to enforce.
        if ($expectedName === '') {
            return $this->checkNationalId($outcome, $documentType, $knownContext, $ocrText, $image);
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

        /*
         * الرقم القومي آخر حاجة بقصد: فحص الاسم والصلاحية أرخص وبيتاخد من
         * رد الموديل اللي معانا أصلاً، والرقم ممكن يستدعي قراءة الصورة
         * بالموديل - مفيش داعي نستدعيها لبطاقة باسم حد تاني أصلاً.
         */
        return $this->checkNationalId($outcome, $documentType, $knownContext, $ocrText, $image);
    }

    /**
     * الرقم القومي بيتقارن حتميًا، مش بتقدير الموديل - وبمصدرين مش مصدر
     * واحد، لأن الـ OCR وحده مش أمين على البطاقة دي.
     *
     * الترتيب:
     *  1) نص الـ OCR: لو الأربعتاشر رقم موجودين فيه بالظبط، خلاص مطابق.
     *  2) ضهر البطاقة: رقمه مطبوع كبير على خلفية فاضية، فأي رقم قومي
     *     سليم مختلف عن المسجل = اختلاف حقيقي.
     *  3) الصورة نفسها: بنخلي الموديل يقرا الأربعتاشر رقم من الصورة
     *     مباشرةً. ده اللي بيحسم الحالة اللي كسرت الفلو - Google Vision
     *     قرا وش البطاقة غلط تلات مرات من تلاتة (مرة بخانة ومرتين
     *     بخانتين)، فأي "تسامح" في المقارنة كان يا إما هيقفل على العميل
     *     الصح يا إما هيقبل رقم غلط.
     *  4) لو محدش عرف يقرا الرقم: دي مش مخالفة، دي صورة مش واضحة -
     *     بنطلب صورة أوضح بدل ما نقول لواحد رقمه صح إنه غلط.
     */
    private function checkNationalId(
        array $outcome,
        string $documentType,
        array $knownContext,
        string $ocrText,
        ?array $image
    ): array {
        if (! in_array($documentType, self::ID_BEARING_DOCUMENTS, true)) {
            return $outcome;
        }

        $expected = (string) preg_replace('/\D/', '', $this->normalizeDigits((string) ($knownContext['expected_national_id'] ?? '')));

        if (strlen($expected) !== 14) {
            return $outcome;
        }

        $digitsInDocument = (string) preg_replace('/\D/', '', $this->normalizeDigits($ocrText));

        $ocrHasExpected = str_contains($digitsInDocument, $expected)
            || str_contains($digitsInDocument, strrev($expected));

        /*
         * ضهر البطاقة: الـ OCR أمين عليه (الرقم مطبوع كبير على خلفية
         * فاضية)، فبيتسأل الأول - ولو قرا رقم قومي سليم مختلف عن المسجل
         * ده اختلاف حقيقي.
         */
        if ($documentType === 'id_card_back') {
            if ($ocrHasExpected) {
                return $this->nationalIdConfirmed($outcome, $expected);
            }

            $onCard = $this->nationalIdsInDigits($digitsInDocument);

            if ($onCard !== []) {
                return $this->nationalIdConflict($outcome, $onCard[0], $expected);
            }
        }

        /*
         * وش البطاقة: نص الـ OCR **مش** مصدر يتأكد بيه هنا. Google Vision
         * قرا نفس البطاقة غلط تلات مرات من تلاتة، ولو العميل كتب بالصدفة
         * نفس الرقم الغلط اللي الـ OCR قراه، المطابقة الحرفية كانت
         * "بتأكد" رقم غلط (حصل فعلاً في التجربة). فالصورة نفسها هي
         * المرجع، والـ OCR بيبقى احتياطي بس لو الموديل معرفش يقرا.
         */
        $fromImage = $this->readNationalIdFromImage($image);

        if ($fromImage === $expected) {
            return $this->nationalIdConfirmed($outcome, $expected);
        }

        if ($fromImage !== null) {
            return $this->nationalIdConflict($outcome, $fromImage, $expected);
        }

        if ($ocrHasExpected) {
            return $this->nationalIdConfirmed($outcome, $expected);
        }

        return $this->rejected(
            $outcome,
            'مش قادر أقرا الرقم القومي في الصورة كويس عشان أتأكد منه. ابعتلي صورة أوضح للبطاقة من فضلك -'
                . ' في ضوء كويس، والكارت كامل في الصورة، والأرقام مش مايلة ولا فيها لمعة.'
        );
    }

    /**
     * الموديل بيقرا الأربعتاشر رقم من الصورة نفسها - مش من نص الـ OCR.
     * بيرجّع الرقم لو اتقرا كامل وواضح وطلع رقم قومي مصري سليم، وnull لو
     * الصورة مش واضحة كفاية (وساعتها بنطلب صورة أوضح، مش بنتهم العميل).
     */
    private function readNationalIdFromImage(?array $image): ?string
    {
        $path = (string) ($image['path'] ?? '');

        if ($path === '' || ! is_readable($path)) {
            return null;
        }

        $binary = @file_get_contents($path);

        if ($binary === false || $binary === '') {
            return null;
        }

        $prompt = <<<'PROMPT'
دي صورة بطاقة رقم قومي مصرية (وش أو ضهر).

اقرا الرقم القومي المكتوب فيها: 14 رقم بالأرقام الهندية (٠١٢٣٤٥٦٧٨٩)،
وحوّلهم لأرقام عادية (0123456789).

مهم جدًا:
- ركّز في كل خانة لوحدها. ٤ و٦ و١ و٠ بيتلخبطوا في الصور الضعيفة.
- لو أي خانة مش واضحة أو مش متأكد منها، خلي all_digits_clear = false.
- متكملش الرقم من عندك ولا تخمّن خانة ناقصة.
- الأرقام التانية في البطاقة (تاريخ الإصدار، تاريخ الانتهاء، الرقم
  المسلسل اللي فيه حروف) مش الرقم القومي.

رجّع JSON بس:
{"digits": "الأربعتاشر رقم أو null", "all_digits_clear": true أو false}
PROMPT;

        try {
            $result = app(GeminiClient::class)->generateText($prompt, 'gemini-3.1-flash-lite', [
                'image_base64' => base64_encode($binary),
                'image_mime' => $image['mime'] ?? 'image/jpeg',
                'temperature' => 0.0,
                'maxOutputTokens' => 120,
                'responseMimeType' => 'application/json',
            ]);

            if (! ($result['ok'] ?? false)) {
                return null;
            }

            $json = $this->extractJson(trim((string) ($result['reply'] ?? $result['text'] ?? '')));

            if (! is_array($json) || ($json['all_digits_clear'] ?? false) !== true) {
                return null;
            }

            $digits = (string) preg_replace('/\D/', '', $this->normalizeDigits((string) ($json['digits'] ?? '')));

            if (strlen($digits) !== 14 || ! ((new EgyptianNationalId())->parse($digits)['valid'] ?? false)) {
                return null;
            }

            return $digits;
        } catch (\Throwable $e) {
            Log::error('national id image read failed', ['message' => $e->getMessage()]);

            return null;
        }
    }

    private function nationalIdConfirmed(array $outcome, string $expected): array
    {
        if ($outcome['valid'] === false && $this->violationIsAboutTheNationalId((string) $outcome['violation_message'])) {
            $outcome['valid'] = true;
            $outcome['violation_message'] = null;
        }

        $outcome['fields']['national_id'] = $expected;

        return $outcome;
    }

    private function nationalIdConflict(array $outcome, string $onCard, string $expected): array
    {
        return $this->rejected(
            $outcome,
            "الرقم القومي المكتوب في البطاقة ({$onCard}) مش نفس الرقم المسجل في الطلب ({$expected})."
                . ' لو الرقم الصح هو اللي في البطاقة، ابعتهولي في رسالة عشان أصححه.'
        );
    }

    /**
     * أرقام قومية حقيقية موجودة في نص المستند.
     *
     * نص الـ OCR فيه أرقام تانية كتير (تواريخ الإصدار والانتهاء، الرقم
     * المسلسل)، فمينفعش أي 14 رقم متتاليين يتحسبوا رقم قومي - بنمرّرهم
     * على نفس التحقق اللي بيتطبق على الرقم اللي العميل بيكتبه (تاريخ
     * ميلاد منطقي + كود محافظة حقيقي).
     *
     * @return array<int, string>
     */
    private function nationalIdsInDigits(string $digits): array
    {
        $parser = new EgyptianNationalId();
        $found = [];

        for ($offset = 0; $offset + 14 <= strlen($digits); $offset++) {
            $candidate = substr($digits, $offset, 14);

            if (($parser->parse($candidate)['valid'] ?? false) && ! in_array($candidate, $found, true)) {
                $found[] = $candidate;
            }
        }

        return $found;
    }

    private function violationIsAboutTheNationalId(string $message): bool
    {
        $text = mb_strtolower($message);

        foreach (['رقم القومي', 'رقم قومي', 'رقم القومى', 'رقم قومى', 'national id', 'national_id'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * الأرقام الهندية (٠-٩) والفارسية (۰-۹) -> أرقام عربية (0-9).
     * البطاقة المصرية مطبوعة بالهندي، والعميل بيكتب رقمه بالعربي، وGoogle
     * Vision بيرجّع الخطين مخلوطين في نفس السطر.
     */
    private function normalizeDigits(string $text): string
    {
        return str_replace(
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $text
        );
    }

    /**
     * هل الاسمين لنفس الشخص؟ نفس المقارنة اللي بتتطبق على المستندات
     * (بتفهم العربي والإنجليزي)، متاحة للـ handlers اللي بتتحقق من اسم
     * حساب في سكرين تطبيق.
     */
    public function namesBelongToSamePerson(string $expected, string $onDocument): bool
    {
        return $this->sharesNamePart($expected, $onDocument);
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

        return $this->sharesTransliteratedName($expected, $onDocument);
    }

    /**
     * نفس الشخص، بس الاسم مكتوب بحروف لاتينية.
     *
     * تطبيقات الشغل (طلبات/أوبر) بتكتب اسم المندوب إنجليزي:
     * "Mohamed Emam Mohamed Kamel" - والمقارنة العربية فوق مستحيل تطابقه،
     * فكان أي مستند بالإنجليزي بيترفض على إنه باسم حد تاني مهما كان صح.
     *
     * المقارنة هنا على **نطق** الاسم مش حروفه: بنحوّل العربي لحروف
     * لاتينية وبنشيل الحروف اللينة من الطرفين، فيتقارن الهيكل الساكن
     * للاسم (محمد -> mhmd، Mohamed/Mohammed/Muhammad -> mhmd).
     *
     * ومشترطين جزئين متطابقين مش جزء واحد، لأن الاختصار بيقرّب أسماء
     * مختلفة من بعضها (محمود ومحمد الاتنين بيبقوا mhmd) - إلا لو المستند
     * أصلاً فيه اسم واحد بس (زي سكرين تطبيق بيكتب الاسم الأول).
     */
    private function sharesTransliteratedName(string $expected, string $onDocument): bool
    {
        $expectedParts = $this->nameSkeletons($expected);
        $documentParts = $this->nameSkeletons($onDocument);

        if ($expectedParts === [] || $documentParts === []) {
            return false;
        }

        $matches = count(array_intersect($expectedParts, $documentParts));

        if ($matches >= 2) {
            return true;
        }

        return $matches === 1 && count($documentParts) === 1;
    }

    /**
     * الهيكل الساكن لكل جزء في الاسم، بعد تحويل العربي للاتيني.
     *
     * @return array<int, string>
     */
    private function nameSkeletons(string $name): array
    {
        $arabicToLatin = [
            'ا' => '', 'أ' => '', 'إ' => '', 'آ' => '', 'ى' => '', 'ء' => '', 'ؤ' => '', 'ئ' => '',
            'ب' => 'b', 'ت' => 't', 'ث' => 't', 'ج' => 'g', 'ح' => 'h', 'خ' => 'kh',
            'د' => 'd', 'ذ' => 'z', 'ر' => 'r', 'ز' => 'z', 'س' => 's', 'ش' => 'sh',
            'ص' => 's', 'ض' => 'd', 'ط' => 't', 'ظ' => 'z', 'ع' => '', 'غ' => 'g',
            'ف' => 'f', 'ق' => 'k', 'ك' => 'k', 'ل' => 'l', 'م' => 'm', 'ن' => 'n',
            'ه' => 'h', 'ة' => 'h', 'و' => 'w', 'ي' => 'y',
        ];

        $text = mb_strtolower($name);
        $text = preg_replace('/[\x{0617}-\x{061A}\x{064B}-\x{0652}]/u', '', $text) ?? $text;
        $text = strtr($text, $arabicToLatin);
        $text = preg_replace('/[^a-z\s]/u', ' ', $text) ?? $text;

        $skeletons = [];

        foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            // j/q/c بيتكتبوا g/k في نقل الأسماء المصرية (Gamal/Jamal).
            $part = strtr($part, ['j' => 'g', 'q' => 'k', 'c' => 'k']);

            // الحروف اللينة بتختلف من كتابة للتانية (Mohamed/Mohammad/Muhammed).
            $part = preg_replace('/[aeiouwy]/', '', $part) ?? '';

            // تكرار الحرف مش فرق (Mohammed -> mhmd).
            $part = preg_replace('/(.)\1+/', '$1', $part) ?? '';

            if (strlen($part) >= 2 && ! in_array($part, $skeletons, true)) {
                $skeletons[] = $part;
            }
        }

        return $skeletons;
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
