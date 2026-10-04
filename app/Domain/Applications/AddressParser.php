<?php

namespace App\Domain\Applications;

use App\Support\ArabicTextNormalizer;

/**
 * The deterministic half of the address split: the marker words every
 * Egyptian address uses (شارع، متفرع من، الدور، شقة، عمارة، بجوار ...) and
 * a list of governorates and well known districts. AddressSplitter (AI)
 * reads the free text; this backs it up when the AI call fails and fills
 * the governorate it left out.
 *
 * Server history 2026-10-04: in 7 of 15 bot requests the governorate and
 * area columns were empty - "34 شارع الشرفاء العشرين فيصل" stayed whole in
 * the street column although فيصل is in Giza.
 */
class AddressParser
{
    /** normalized spelling => the governorate as the form wants it */
    private const GOVERNORATES = [
        'القاهره' => 'القاهرة', 'الجيزه' => 'الجيزة', 'جيزه' => 'الجيزة', 'القليوبيه' => 'القليوبية', 'قليوبيه' => 'القليوبية',
        'الاسكندريه' => 'الإسكندرية', 'اسكندريه' => 'الإسكندرية', 'المنوفيه' => 'المنوفية', 'منوفيه' => 'المنوفية',
        'الشرقيه' => 'الشرقية', 'شرقيه' => 'الشرقية', 'الدقهليه' => 'الدقهلية', 'دقهليه' => 'الدقهلية', 'الغربيه' => 'الغربية',
        'البحيره' => 'البحيرة', 'كفر الشيخ' => 'كفر الشيخ', 'دمياط' => 'دمياط', 'بورسعيد' => 'بورسعيد', 'بور سعيد' => 'بورسعيد',
        'الاسماعيليه' => 'الإسماعيلية', 'اسماعيليه' => 'الإسماعيلية', 'السويس' => 'السويس', 'الفيوم' => 'الفيوم', 'فيوم' => 'الفيوم',
        'بني سويف' => 'بني سويف', 'المنيا' => 'المنيا', 'اسيوط' => 'أسيوط', 'سوهاج' => 'سوهاج', 'قنا' => 'قنا', 'الاقصر' => 'الأقصر',
        'اسوان' => 'أسوان', 'البحر الاحمر' => 'البحر الأحمر', 'مطروح' => 'مطروح', 'الوادي الجديد' => 'الوادي الجديد',
        'شمال سيناء' => 'شمال سيناء', 'جنوب سيناء' => 'جنوب سيناء',
    ];

    /** well known district (normalized) => governorate - the longest match wins */
    private const DISTRICTS = [
        'مدينه نصر' => 'القاهرة', 'مصر الجديده' => 'القاهرة', 'هليوبوليس' => 'القاهرة', 'عين شمس' => 'القاهرة', 'المطريه' => 'القاهرة',
        'جسر السويس' => 'القاهرة', 'الزيتون' => 'القاهرة', 'حلوان' => 'القاهرة', 'المعادي' => 'القاهرة', 'زهراء المعادي' => 'القاهرة',
        'دار السلام' => 'القاهرة', 'البساتين' => 'القاهرة', 'مدينه السلام' => 'القاهرة', 'النزهه' => 'القاهرة', 'التجمع' => 'القاهرة',
        'القاهره الجديده' => 'القاهرة', 'الشروق' => 'القاهرة', 'العبور' => 'القاهرة', 'مدينه بدر' => 'القاهرة', 'المرج' => 'القاهرة',
        'شبرا' => 'القاهرة', 'روض الفرج' => 'القاهرة', 'الزاويه الحمرا' => 'القاهرة', 'الاميريه' => 'القاهرة', 'حدائق القبه' => 'القاهرة',
        'العباسيه' => 'القاهرة', 'السيده زينب' => 'القاهرة', 'المقطم' => 'القاهرة', 'المنيل' => 'القاهرة', 'عابدين' => 'القاهرة',
        'وسط البلد' => 'القاهرة', 'رمسيس' => 'القاهرة', 'الخليفه' => 'القاهرة', 'مصر القديمه' => 'القاهرة', 'طره' => 'القاهرة',
        'المعصره' => 'القاهرة', 'التبين' => 'القاهرة', ' الوايلي' => 'القاهرة', 'الحرفيين' => 'القاهرة', 'السلام' => 'القاهرة',
        'العاشر من رمضان' => 'الشرقية', 'الهرم' => 'الجيزة', 'فيصل' => 'الجيزة', 'الدقي' => 'الجيزة', 'العجوزه' => 'الجيزة',
        'المهندسين' => 'الجيزة', 'بولاق الدكرور' => 'الجيزة', 'المنيب' => 'الجيزة', 'الوراق' => 'الجيزة', 'العمرانيه' => 'الجيزة',
        'الطالبيه' => 'الجيزة', 'اكتوبر' => 'الجيزة', 'الشيخ زايد' => 'الجيزة', 'حدائق الاهرام' => 'الجيزة', 'البدرشين' => 'الجيزة',
        'العياط' => 'الجيزة', 'ابو النمرس' => 'الجيزة', 'الحوامديه' => 'الجيزة', 'كرداسه' => 'الجيزة', 'ساقيه مكي' => 'الجيزة',
        'امبابه' => 'الجيزة', 'ارض اللواء' => 'الجيزة', 'صفط اللبن' => 'الجيزة', 'المريوطيه' => 'الجيزة', 'سقاره' => 'الجيزة',
        'مدينتي' => 'القاهرة', 'الرحاب' => 'القاهرة', 'عزبه النخل' => 'القاهرة', 'الالف مسكن' => 'القاهرة', 'منشيه ناصر' => 'القاهرة',
        'حدائق اكتوبر' => 'الجيزة', 'الهضبه الوسطي' => 'القاهرة', 'القلج' => 'القليوبية', 'ابو زعبل' => 'القليوبية', 'كفر الشرفا' => 'القليوبية',
        'شبرا الخيمه' => 'القليوبية', 'الخصوص' => 'القليوبية', 'القناطر' => 'القليوبية', 'بنها' => 'القليوبية', 'قليوب' => 'القليوبية',
        'شبين القناطر' => 'القليوبية', 'طوخ' => 'القليوبية', 'الخانكه' => 'القليوبية', 'بهتيم' => 'القليوبية', 'مسطرد' => 'القليوبية',
        'شبين الكوم' => 'المنوفية', 'بركه السبع' => 'المنوفية', 'منوف' => 'المنوفية', 'قويسنا' => 'المنوفية', 'السادات' => 'المنوفية',
        'الزقازيق' => 'الشرقية', 'المنصوره' => 'الدقهلية', 'طنطا' => 'الغربية', 'المحله' => 'الغربية', 'دمنهور' => 'البحيرة',
    ];

    private const STOP = '(?=\s*(?:[،,\-\n]|$|(?:ال)?(?:شارع|ش|متفرع[ةه]?|دور|شق[ةه]|عمار[ةه]|عقار|بجوار|جنب|جمب|[اأ]مام|قدام|قصاد|خلف|ورا|علام[ةه]|محافظ[ةه]|منطق[ةه]|مركز|قري[ةه]|ميدان)(?!\p{L})))';

    /** a floor said as a word */
    private const FLOOR_WORDS = '(?:ال)?(?:[اأ]رضي|[اأ]ول|تاني|ثاني|تالت|ثالث|رابع|خامس|سادس|سابع|تامن|ثامن|تاسع|عاشر|[اأ]خير|بدروم)';

    /** @return array<string, string> part => value (governorate, area, street, branch_street, building_number, floor, apartment, landmark) */
    public function parse(string $line): array
    {
        $text = $this->prepare($line);
        $parts = [];

        if (preg_match('/(?:بجوار|جنب|جمب|[اأ]مام|قدام|قصاد|خلف|ورا|بالقرب من|علام[ةه] مميز[ةه][:\s]*)\s+([^،,\n]+)/u', $text, $m)) {
            $parts['landmark'] = trim($m[1]);
            $text = str_replace($m[0], ' ', $text);
        }

        if (preg_match('/متفرع[هة]?\s+(?:من\s+)?(?:شارع\s+|ش\s+)?(.+?)'.self::STOP.'/u', $text, $m)) {
            $parts['branch_street'] = trim($m[1]);
            $text = str_replace($m[0], ' ', $text);
        }

        if (preg_match('/(?:ال)?دور\s+(?:رقم\s+)?(\d+|'.self::FLOOR_WORDS.')(?!\p{L})/u', $text, $m)) {
            $parts['floor'] = trim($m[1]);
            $text = str_replace($m[0], ' ', $text);
        }

        if (preg_match('/(?:ال)?شق[ةه]\s+(?:رقم\s+)?(\d+)/u', $text, $m)) {
            $parts['apartment'] = trim($m[1]);
            $text = str_replace($m[0], ' ', $text);
        }

        if (preg_match('/(?:رقم العقار|عمار[ةه]|عقار|منزل|بيت|فيلا|بلوك|قطع[ةه])\s+(?:رقم\s+)?(\d+[^\s،,]*)/u', $text, $m)
            || preg_match('/^\s*(\d{1,4})\s+(?=(?:شارع|ش)(?!\p{L}))/u', $text, $m)) {
            $parts['building_number'] = trim($m[1]);
            $text = str_replace($m[0], ' ', $text);
        }

        if (preg_match('/(?:^|\s|[،,])(?:ال)?(?:شارع|ش)\s+(.+?)'.self::STOP.'/u', $text, $m)) {
            $parts['street'] = trim($m[1]);
            $text = str_replace($m[0], ' ', $text);
        }

        [$governorate, $area] = $this->placeIn($line);

        // what no marker took ("الهرم محطه مشعل") is where he lives: the area
        $rest = preg_replace('/(?<!\p{L})(?:محافظ[ةه]|منطق[ةه]|مركز)(?!\p{L})/u', ' ', $text);

        foreach (array_merge(array_keys(self::GOVERNORATES), array_values(self::GOVERNORATES)) as $spelling) {
            $rest = preg_replace('/(?<!\p{L})'.preg_quote($spelling, '/').'(?!\p{L})/u', ' ', (string) $rest);
        }

        $rest = trim((string) preg_replace('/\s+/u', ' ', preg_replace('/^[\s،,.\-]+|[\s،,.\-]+$/u', '', (string) preg_replace('/\s*[،,\-]\s*/u', ' - ', (string) $rest))), ' -');

        // "أول"، "من"، "الشارع من" alone say nothing about the place
        $rest = trim((string) preg_replace('/^(?:(?:ال)?شارع\s+)?(?:(?:[اأ]ول|[اآ]خر|في|ف|من|عند|ناحي[ةه])\s*)+/u', '', $rest));

        if ($rest !== '' && mb_strlen($rest) > 2 && ($area === null || (str_starts_with($rest, $area) || str_contains($rest, ' '.$area)) && count(preg_split('/\s+/u', $rest)) <= 6)) {
            $area = $rest;
        }

        if ($governorate !== null) {
            $parts['governorate'] = $governorate;
        }

        if ($area !== null) {
            $parts['area'] = $area;
        }

        // "متفرع من جمال عبد الناصر المنيب الجيزه": the area and governorate
        // at the end of a street belong in their own columns
        foreach (['street', 'branch_street'] as $part) {
            if (isset($parts[$part])) {
                $parts[$part] = trim((string) preg_replace('/\s+(?:محافظ[ةه]\s+)?(?:'.implode('|', array_map(fn ($g) => preg_quote($g, '/'), array_merge(array_keys(self::GOVERNORATES), array_values(self::GOVERNORATES)))).')$/u', '', $parts[$part]));
            }

            foreach (array_filter([$parts['area'] ?? null]) as $place) {
                if (! isset($parts[$part])) {
                    continue;
                }

                $stripped = trim((string) preg_replace('/\s*'.preg_quote($place, '/').'\s*$/u', '', $parts[$part]));

                // "شارع الهرم" is a street named after the district, not the district
                if ($stripped === '') {
                    unset($parts['area']);
                } else {
                    $parts[$part] = $stripped;
                }
            }
        }

        return array_filter($parts, fn ($v) => $v !== '');
    }

    /**
     * The governorate named in the text, else the one of a district named in
     * it; and that district.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function placeIn(string $text): array
    {
        $normalized = ' '.ArabicTextNormalizer::normalize($this->prepare($text)).' ';
        $governorate = null;
        $area = null;

        foreach (self::GOVERNORATES as $spelling => $name) {
            if (preg_match('/(?<!\p{L})'.preg_quote(ArabicTextNormalizer::normalize($spelling), '/').'(?!\p{L})/u', $normalized)) {
                $governorate = $name;

                break;
            }
        }

        $districts = self::DISTRICTS;
        uksort($districts, fn ($a, $b) => mb_strlen(trim($b)) <=> mb_strlen(trim($a)));

        foreach ($districts as $spelling => $name) {
            $spelling = trim($spelling);

            if (preg_match('/(?<!\p{L})'.preg_quote(ArabicTextNormalizer::normalize($spelling), '/').'(?!\p{L})/u', $normalized)) {
                $area = $this->originalSpelling($text, $spelling);
                $governorate ??= $name;

                break;
            }
        }

        return [$governorate, $area];
    }

    public function governorateOf(string $area): ?string
    {
        return $this->placeIn($area)[0];
    }

    /** "فيصل" as he wrote it ("فيصل"، "الهرم") rather than the normalized form. */
    private function originalSpelling(string $text, string $normalizedSpelling): string
    {
        $words = preg_split('/\s+/u', trim($text));
        $count = count(preg_split('/\s+/u', $normalizedSpelling));

        for ($i = 0; $i + $count <= count($words); $i++) {
            $slice = implode(' ', array_slice($words, $i, $count));

            $slice = preg_replace('/^[\s،,.\-]+|[\s،,.\-]+$/u', '', $slice);

            if (ArabicTextNormalizer::normalize($this->prepare($slice)) === ArabicTextNormalizer::normalize($normalizedSpelling)) {
                // trim() works on bytes and cut the Arabic comma in half
                return preg_replace('/^[\s،,.\-]+|[\s،,.\-]+$/u', '', $slice);
            }
        }

        return $normalizedSpelling;
    }

    /** Digits and spacing only - the words stay as he wrote them. */
    private function prepare(string $text): string
    {
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $text = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $text);
        // "48ش الحرية" / "ش.الحرية"
        $text = preg_replace('/(\d)(شارع|ش(?=\s|\.))/u', '$1 $2', $text);

        return trim(preg_replace('/\s+/u', ' ', str_replace(['ش.', '.'], ['ش ', ' '], $text)));
    }
}
