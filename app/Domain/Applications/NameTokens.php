<?php

namespace App\Domain\Applications;

use App\Support\ArabicTextNormalizer;

/**
 * Compares Arabic person names as ordered word sequences, tolerant of the
 * usual spacing variants ("عبدالمحسن" / "عبد المحسن", "ابوبكر" / "ابو بكر").
 */
final class NameTokens
{
    /** @return string[] */
    public static function of(string $name): array
    {
        $name = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $name);
        $name = ArabicTextNormalizer::normalize($name);
        $name = preg_replace('/[^\p{L}\s]+/u', ' ', $name);
        // Compound-name prefixes join the following word.
        $name = preg_replace('/(^|\s)(عبد|ابو|ابي)\s+/u', '$1$2', $name);
        $name = preg_replace('/(عبد|ابو|ابي)ال(?=\p{L})/u', '$1', $name);

        return array_values(array_filter(explode(' ', trim(preg_replace('/\s+/u', ' ', $name)))));
    }

    /**
     * $shorter's words appear in $longer, in the same order, and $longer has
     * more words (so $longer is the fuller form of the same name).
     */
    public static function isStrictSubset(string $shorter, string $longer): bool
    {
        $a = self::of($shorter);
        $b = self::of($longer);

        if ($a === [] || count($a) >= count($b) || count($a) < 2) {
            return false;
        }

        $i = 0;

        foreach ($b as $word) {
            if ($i < count($a) && $word === $a[$i]) {
                $i++;
            }
        }

        return $i === count($a);
    }
}
