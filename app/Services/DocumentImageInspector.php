<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * بيبص على *الصورة نفسها* قبل أي استخراج بيانات.
 *
 * قبل كده كل تحليل المستندات كان بيشتغل على نص الـ OCR بس
 * (`DocumentDataExtractor`)، ومن غير ما حد يشوف الصورة أصلًا. النتيجة
 * اللي اتشافت في الاختبار الحقيقي:
 *
 *  - صورة موتوسيكل اتبعتت مكان البطاقة -> "مش قادر أقرا الرقم القومي في
 *    الصورة كويس، ابعتلي صورة أوضح للبطاقة". يعني بنقول للعميل صوّر
 *    الموتوسيكل تاني بجودة أعلى.
 *  - سكرين تطبيق طلبات اتبعت مكان البطاقة -> "البطاقة دي مكتوب فيها اسم
 *    تاني، لازم المستندات تكون باسمك" - اتهام للعميل إنه بعت بطاقة حد
 *    تاني، والصورة أصلًا مش بطاقة.
 *
 * الكلاس ده بيرد على سؤالين بس، وبيرد عليهم من الصورة مباشرة:
 *   ١) الصورة دي إيه فعلًا؟ وهل هي اللي طلبناها؟
 *   ٢) لو هي اللي طلبناها - هل واضحة كفاية إننا نقرا منها؟
 *
 * ملحوظة مهمة عن معنى "واضحة": المطلوب صورة *مقروءة*، مش صورة مثالية.
 * البرومبت بيقول كده صراحة، لأن رفض صور كويسة أسوأ بكتير من قبول صورة
 * متوسطة - العميل بيزهق ويسيب بعد تاني أو تالت طلب إعادة تصوير.
 */
class DocumentImageInspector
{
    /**
     * وصف كل نوع مستند بالعربي، عشان الموديل يعرف بيدور على إيه بالظبط
     * وعشان نقدر نكتب رسالة مفهومة للعميل.
     *
     * @var array<string, array{label: string, looks_like: string}>
     */
    private const DOCUMENT_SPECS = [
        'id_card_front' => [
            'label' => 'وش البطاقة الشخصية',
            'looks_like' => 'كارت بطاقة رقم قومي مصرية - الوش اللي فيه صورة صاحبها واسمه وعنوانه',
        ],
        'id_card_back' => [
            'label' => 'ضهر البطاقة الشخصية',
            'looks_like' => 'ضهر كارت البطاقة المصرية - فيه الرقم القومي والمهنة والحالة الاجتماعية',
        ],
        'driver_license' => [
            'label' => 'رخصة القيادة',
            'looks_like' => 'رخصة قيادة مصرية (كارت أو ورقة رسمية فيها بيانات السائق)',
        ],
        'vehicle_license' => [
            'label' => 'رخصة المركبة',
            'looks_like' => 'رخصة تسيير مركبة مصرية',
        ],
        'work_app_screens' => [
            'label' => 'سكرين من تطبيق الشغل',
            'looks_like' => 'لقطة شاشة من موبايل لتطبيق شغل (طلبات، أوبر، إن درايف، كريم، مرسول، بريد...) فيها حساب المندوب أو أرباحه أو تاريخ انضمامه',
        ],
        'trips_screenshot' => [
            'label' => 'سكرين من تطبيق الشغل',
            'looks_like' => 'لقطة شاشة من موبايل لتطبيق شغل فيها الحساب والرحلات',
        ],
        'salary_slip' => [
            'label' => 'مفردات المرتب',
            'looks_like' => 'ورقة مفردات مرتب أو بيان راتب من جهة العمل',
        ],
        'pension_statement' => [
            'label' => 'بيان المعاش',
            'looks_like' => 'بيان معاش رسمي',
        ],
        'bank_statement' => [
            'label' => 'كشف الحساب البنكي',
            'looks_like' => 'كشف حساب بنكي فيه حركة الحساب',
        ],
        'activity_photo' => [
            'label' => 'صورة النشاط أو المحل',
            'looks_like' => 'صورة لمحل أو ورشة أو نشاط تجاري',
        ],
    ];

    /**
     * @return array{
     *   ok: bool,
     *   verdict: string,          // ok | wrong_document | unreadable | unknown
     *   detected: string|null,    // وصف بالعربي للي في الصورة فعلًا
     *   message: string|null      // الرسالة الجاهزة للعميل، أو null لو الصورة سليمة
     * }
     */
    public function inspect(string $absolutePath, string $mime, string $expectedDocType): array
    {
        if (! is_readable($absolutePath)) {
            return $this->unknown();
        }

        $spec = self::DOCUMENT_SPECS[$expectedDocType] ?? null;

        if (! $spec) {
            // نوع مستند مش معرّف عندنا - منوقفش الطلب عشان فحص مش
            // قادرين نعمله صح.
            return $this->unknown();
        }

        $bytes = @file_get_contents($absolutePath);

        if ($bytes === false || $bytes === '') {
            return $this->unknown();
        }

        try {
            $result = app(GeminiClient::class)->generateText(
                prompt: $this->prompt($spec),
                preferredModelCode: config('gemini.models.fast'),
                options: [
                    'image_base64' => base64_encode($bytes),
                    'image_mime' => $mime ?: 'image/jpeg',
                    'timeout' => 20,
                    'temperature' => 0.1,
                    'thinkingBudget' => 0,
                    'maxOutputTokens' => 300,
                    'responseMimeType' => 'application/json',
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('document image inspection failed', [
                'doc_type' => $expectedDocType,
                'error' => $e->getMessage(),
            ]);

            return $this->unknown();
        }

        if (! ($result['ok'] ?? false)) {
            return $this->unknown();
        }

        $data = $this->decode((string) ($result['reply'] ?? ''));

        if ($data === null) {
            return $this->unknown();
        }

        $isExpected = (bool) ($data['is_expected_document'] ?? false);
        $readable = (bool) ($data['readable'] ?? true);
        $detected = trim((string) ($data['what_it_is'] ?? '')) ?: null;
        $confidence = (float) ($data['confidence'] ?? 0);

        /*
         * الشك بيتحسب لصالح العميل. لو الموديل مش متأكد إن الصورة نوعها
         * غلط، بنسيب الاستخراج العادي يكمّل بدل ما نرجّع صورة صح لعميل
         * صوّرها من أول مرة.
         */
        if (! $isExpected && $confidence >= 0.7) {
            return [
                'ok' => true,
                'verdict' => 'wrong_document',
                'detected' => $detected,
                'message' => $this->wrongDocumentMessage($spec['label'], $detected),
            ];
        }

        if ($isExpected && ! $readable) {
            return [
                'ok' => true,
                'verdict' => 'unreadable',
                'detected' => $detected,
                'message' => $this->unreadableMessage(
                    $spec['label'],
                    trim((string) ($data['quality_issue'] ?? '')),
                    $expectedDocType
                ),
            ];
        }

        return ['ok' => true, 'verdict' => 'ok', 'detected' => $detected, 'message' => null];
    }

    /**
     * رسالة "الصورة دي مش اللي طلبناها". الأهم فيها إننا نقول للعميل
     * إحنا شفنا إيه - "دي صورة موتوسيكل" - عشان يعرف إنه بعت غلط، مش
     * يفتكر إن التصوير هو المشكلة ويصوّر نفس الحاجة تاني.
     */
    private function wrongDocumentMessage(string $expectedLabel, ?string $detected): string
    {
        $seen = $detected !== null && $detected !== ''
            ? "الصورة اللي وصلتني دي {$detected}"
            : 'الصورة اللي وصلتني مش دي';

        return "معلش يا فندم، {$seen}.\nأنا محتاج {$expectedLabel}، ابعتهالي لو سمحت.";
    }

    /**
     * رسالة "الصورة مش واضحة". بنقول السبب بالظبط لو عرفناه، عشان
     * العميل يعرف يظبط إيه في اللقطة الجاية بدل ما يبعت نفس الصورة تاني.
     */
    private function unreadableMessage(string $expectedLabel, string $issue, string $docType = ''): string
    {
        /*
         * السكرين شوت حالة مختلفة: نصيحة "خلي إيدك ثابتة" مالهاش معنى
         * هنا. أغلب السكرينات الوحشة سببها إن العميل صوّر شاشة موبايله
         * بموبايل تاني بدل ما ياخد سكرين شوت.
         */
        if (in_array($docType, ['work_app_screens', 'trips_screenshot'], true)) {
            return "معلش يا فندم، {$expectedLabel} مش واضح كفاية عندي.\n"
                . 'خد سكرين شوت من الموبايل نفسه (مش تصوير الشاشة بموبايل تاني) وابعته لو سمحت.';
        }

        $hint = match (true) {
            str_contains($issue, 'blur') => 'خلي إيدك ثابتة شوية والصورة تطلع مركزة',
            str_contains($issue, 'glare') => 'بعّدها شوية عن النور عشان اللمعة',
            str_contains($issue, 'dark') => 'صوّرها في مكان فيه نور أحسن',
            str_contains($issue, 'crop') => 'خلي بالك تكون ظاهرة كاملة في الصورة',
            str_contains($issue, 'small') => 'قرّب عليها شوية',
            default => 'يكون فيه نور كويس وتكون ظاهرة كاملة',
        };

        return "معلش يا فندم، صورة {$expectedLabel} مش واضحة كفاية.\nممكن تصوّرها تاني و{$hint}؟";
    }

    private function prompt(array $spec): string
    {
        $label = $spec['label'];
        $looksLike = $spec['looks_like'];

        return <<<TXT
        بص على الصورة دي وجاوب على سؤالين بس. رجّع JSON بالشكل ده وبس:

        {
          "what_it_is": "وصف قصير جدًا بالعربي المصري للي في الصورة فعلًا (مثال: صورة موتوسيكل، سكرين تطبيق طلبات، بطاقة رقم قومي، ورقة مكتوبة، صورة شخصية)",
          "is_expected_document": true أو false,
          "confidence": رقم من 0 لـ 1,
          "readable": true أو false,
          "quality_issue": "blur" أو "glare" أو "dark" أو "crop" أو "small" أو null
        }

        المستند المطلوب من العميل هو: {$label}
        وشكله المفروض: {$looksLike}

        قواعد:
        - is_expected_document = true بس لو الصورة فعلًا من نوع المستند
          المطلوب. لو حاجة تانية خالص (موتوسيكل، أكل، شخص، سكرين تطبيق
          وإحنا طالبين بطاقة، بطاقة وإحنا طالبين سكرين) خليها false.
        - confidence = مدى تأكدك من is_expected_document. لو الصورة مش
          واضحة لدرجة إنك مش قادر تحدد نوعها، خلي confidence أقل من 0.5.
        - readable = هل البيانات المكتوبة في الصورة ممكن تتقرا؟
          **مهم جدًا**: احنا عايزين صورة *مقروءة*، مش صورة مثالية. لو
          الكلام والأرقام باينين وممكن حد يقراهم - حتى لو الصورة فيها
          ميلان بسيط أو إضاءة متوسطة أو أصابع في الطرف - خلي readable =
          true. خليها false بس لما الكتابة فعلًا مش ممكن تتقرا.
        - لو is_expected_document = false، حط readable = true (مش موضوعنا).
        - رد JSON بس من غير أي كلام تاني.
        TXT;
    }

    private function decode(string $raw): ?array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```[a-zA-Z]*\s*/u', '', $raw);
        $raw = preg_replace('/\s*```$/u', '', (string) $raw);

        $decoded = json_decode(trim((string) $raw), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * الفحص مش قادر يحكم - بنعدّي عشان الاستخراج العادي يكمّل. الكلاس ده
     * طبقة أمان زيادة، مش بوابة تقدر توقف طلب لو Gemini وقع.
     */
    private function unknown(): array
    {
        return ['ok' => false, 'verdict' => 'unknown', 'detected' => null, 'message' => null];
    }
}
