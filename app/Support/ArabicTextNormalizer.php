<?php

namespace App\Support;

/**
 * Core Arabic-text normalization for catalog name matching (T09), reusing
 * the normalization ideas from the deleted `MachineSearchService`
 * (`git show HEAD:app/Services/MachineSearchService.php`) - just the
 * general letter/digit normalization, not its large hardcoded brand-typo
 * dictionary (that kind of business knowledge now belongs in each
 * machine's editable `aliases`, not in code - plan principle 2).
 */
class ArabicTextNormalizer
{
    public static function normalize(string $text): string
    {
        $text = self::arabicDigitsToEnglish($text);
        $text = mb_strtolower($text);

        // Tatweel (ـ) and harakat are typography, not letters: the catalog
        // holds "فيجــوري 3" and "بلسـر ١٨٠", which no customer types.
        $text = preg_replace('/[\x{0640}\x{064B}-\x{065F}\x{0670}]/u', '', $text);

        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace('ى', 'ي', $text);
        $text = str_replace('ؤ', 'و', $text);
        $text = str_replace('ئ', 'ي', $text);

        // Strip a leading "ال" (the definite article) before an Arabic word.
        $text = preg_replace('/\bال(?=[\p{Arabic}]{2,})/u', '', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private static function arabicDigitsToEnglish(string $text): string
    {
        return str_replace(
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $text
        );
    }
}
