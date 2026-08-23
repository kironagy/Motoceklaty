<?php

namespace App\Support;

/**
 * Deterministic best-effort address component extraction, so the LLM
 * extraction prompt only has to fill whatever this parser couldn't
 * confidently resolve. Component list matches the business's own address
 * rule (ai_memories "قواعد العناوين"): governorate, city, area, street,
 * building, floor, apartment, landmark.
 */
class AddressParser
{
    private const GOVERNORATES = [
        'القاهرة', 'الجيزة', 'الاسكندرية', 'الإسكندرية', 'القليوبية', 'الشرقية',
        'الدقهلية', 'الغربية', 'المنوفية', 'البحيرة', 'كفر الشيخ', 'دمياط',
        'بورسعيد', 'الاسماعيلية', 'الإسماعيلية', 'السويس', 'شمال سيناء', 'جنوب سيناء',
        'الفيوم', 'بني سويف', 'المنيا', 'اسيوط', 'أسيوط', 'سوهاج', 'قنا',
        'الاقصر', 'الأقصر', 'اسوان', 'أسوان', 'البحر الاحمر', 'البحر الأحمر',
        'الوادي الجديد', 'مطروح',
    ];

    private const CITY_HINTS = [
        '6 أكتوبر', '٦ أكتوبر', 'اكتوبر', 'أكتوبر', 'الشيخ زايد', 'زايد',
        'مدينة نصر', 'نصر سيتي', 'المعادي', 'مصر الجديدة', 'حلوان', 'العبور',
        'بدر', 'الشروق', 'التجمع', 'العاشر من رمضان', 'الرحاب',
    ];

    private const OWNERSHIP_OWNER_WORDS = ['ملك', 'مالك', 'ملكي', 'تمليك'];
    private const OWNERSHIP_RENTER_WORDS = ['ايجار', 'إيجار', 'مستاجر', 'مستأجر', 'مؤجر'];

    public function parse(string $text): array
    {
        $components = [
            'governorate' => null,
            'city' => null,
            'area' => null,
            'street' => null,
            'building' => null,
            'floor' => null,
            'apartment' => null,
            'landmark' => null,
            'ownership' => null,
        ];

        $text = trim($text);

        if ($text === '') {
            return $components;
        }

        foreach (self::GOVERNORATES as $governorate) {
            if (mb_stripos($text, $governorate) !== false) {
                $components['governorate'] = $governorate;
                break;
            }
        }

        foreach (self::CITY_HINTS as $city) {
            if (mb_stripos($text, $city) !== false) {
                $components['city'] = $city;
                break;
            }
        }

        $remaining = $text;

        if (preg_match('/^(.*?)\s*شارع\s*([^\n,،]+)/u', $text, $m)) {
            $components['street'] = trim($m[2]);
            $leadingPhrase = trim($m[1]);
            $remaining = str_replace($m[0], ' ', $remaining);
        } elseif (preg_match('/^(.*?)\s*ش[\.\s]\s*([^\n,،]+)/u', $text, $m)) {
            $components['street'] = trim($m[2]);
            $leadingPhrase = trim($m[1]);
            $remaining = str_replace($m[0], ' ', $remaining);
        }

        /*
         * Egyptian addresses conventionally lead with a district/area name
         * before "شارع" (e.g. "المهندسين شارع جامعة الدول"). Our
         * governorate/city hint lists can't be exhaustive, so when a
         * street was found and no governorate/city already matched, treat
         * that leading phrase as the area — it's still a real, distinct
         * piece of information the customer gave, not an invented value.
         */
        if (
            isset($leadingPhrase) && $leadingPhrase !== ''
            && $components['area'] === null && $components['governorate'] === null && $components['city'] === null
        ) {
            $components['area'] = $leadingPhrase;
        }

        if (preg_match('/(?:عماره|عمارة|عمار)\s*:?\s*(\d+)/u', $text, $m) || preg_match('/عقار\s*:?\s*(\d+)/u', $text, $m)) {
            $components['building'] = $m[1];
            $remaining = str_replace($m[0], ' ', $remaining);
        }

        if (preg_match('/الدور\s*:?\s*(\S+)/u', $text, $m) || preg_match('/دور\s*:?\s*(\S+)/u', $text, $m)) {
            $components['floor'] = $this->trimPunctuation($m[1]);
            $remaining = str_replace($m[0], ' ', $remaining);
        }

        if (preg_match('/شقة\s*:?\s*(\S+)/u', $text, $m) || preg_match('/شقه\s*:?\s*(\S+)/u', $text, $m)) {
            $components['apartment'] = $this->trimPunctuation($m[1]);
            $remaining = str_replace($m[0], ' ', $remaining);
        }

        if (preg_match('/(?:علامة مميزة|بجوار|جنب|قرب|بالقرب من|امام|أمام|خلف|وراء|جمب)\s*:?\s*([^\n,،]+)/u', $text, $m)) {
            $components['landmark'] = trim($m[1]);
            $remaining = str_replace($m[0], ' ', $remaining);
        }

        foreach (self::OWNERSHIP_OWNER_WORDS as $word) {
            if (mb_stripos($text, $word) !== false) {
                $components['ownership'] = 'ملك';
                $remaining = str_replace($word, ' ', $remaining);
                break;
            }
        }

        if ($components['ownership'] === null) {
            foreach (self::OWNERSHIP_RENTER_WORDS as $word) {
                if (mb_stripos($text, $word) !== false) {
                    $components['ownership'] = 'إيجار';
                    $remaining = str_replace($word, ' ', $remaining);
                    break;
                }
            }
        }

        /*
         * لو لسه معندناش شارع بعد استخراج كل حاجة تانية، والباقي من
         * النص فيه كلام حقيقي (مش بس مسافات/فاصلة)، اعتبره اسم الشارع
         * أو المنطقة الفرعية - العميل غالبًا كتب حي/منطقة زي "الحصري"
         * من غير ما يستخدم كلمة "شارع" صراحة، ومحتاجينها عشان
         * الاكتمال مش نسيبها ضايعة ونفضل نسأل عليها تاني.
         */
        if ($components['street'] === null) {
            if ($components['city']) {
                $remaining = str_replace($components['city'], ' ', $remaining);
            }
            if ($components['governorate']) {
                $remaining = str_replace($components['governorate'], ' ', $remaining);
            }

            $remaining = trim(preg_replace('/[\s,،]+/u', ' ', $remaining));

            // Strip filler words ("ساكن في", "أنا", ...) that aren't part
            // of a place name, so a bare "ساكن في 6 أكتوبر" doesn't leave
            // "ساكن في" behind and get mistaken for a street name.
            $fillerWords = ['ساكن', 'ساكنه', 'ساكنة', 'في', 'من', 'انا', 'أنا', 'ده', 'دي', 'منطقة', 'منطقه'];
            $words = array_filter(
                preg_split('/\s+/u', $remaining, -1, PREG_SPLIT_NO_EMPTY) ?: [],
                fn ($word) => ! in_array($word, $fillerWords, true)
            );
            $remaining = implode(' ', $words);

            if (mb_strlen($remaining) >= 2) {
                $components['street'] = $remaining;
            }
        }

        return $components;
    }

    /**
     * PHP's trim() takes its character mask as a byte string, not a
     * character list - passing a multi-byte character like the Arabic
     * comma "،" makes it strip either of that character's two individual
     * UTF-8 bytes from the ends, which can slice through the FIRST byte
     * of an adjacent multi-byte Arabic letter and leave an invalid,
     * unencodable UTF-8 string behind (this is exactly what crashed
     * saving the conversation earlier - trim($m[1], " .،") turned "التاني"
     * into a mangled, un-JSON-encodable string). Regex trimming is
     * character-aware and safe here.
     */
    private function trimPunctuation(string $value): string
    {
        return trim(preg_replace('/^[\s.،]+|[\s.،]+$/u', '', $value) ?? '');
    }

    /**
     * COMPLETE/PARTIAL/MISSING status for one address, given its known
     * components. Matches the business's own address rule (ai_memories
     * "قواعد العناوين"): an address is only COMPLETE once area, street,
     * building, floor, apartment AND a landmark are all known.
     * $requireOwnership additionally requires "ملك ولا إيجار" - only
     * meaningful for the home address, not the work address.
     */
    public function status(array $components, bool $requireOwnership = false): array
    {
        $hasAreaOrGovernorate = filled($components['area'] ?? null)
            || filled($components['governorate'] ?? null)
            || filled($components['city'] ?? null);

        $requiredMet = [
            'area_or_governorate' => $hasAreaOrGovernorate,
            'street' => filled($components['street'] ?? null),
            'building' => filled($components['building'] ?? null),
            'floor' => filled($components['floor'] ?? null),
            'apartment' => filled($components['apartment'] ?? null),
            'landmark' => filled($components['landmark'] ?? null),
        ];

        if ($requireOwnership) {
            $requiredMet['ownership'] = filled($components['ownership'] ?? null);
        }

        $missing = array_keys(array_filter($requiredMet, fn ($met) => ! $met));

        $anyComponentPresent = collect($components)->filter(fn ($value) => filled($value))->isNotEmpty();

        if (empty($missing)) {
            return ['status' => 'complete', 'missing' => []];
        }

        if ($anyComponentPresent) {
            return ['status' => 'partial', 'missing' => $missing];
        }

        return ['status' => 'missing', 'missing' => $missing];
    }
}
