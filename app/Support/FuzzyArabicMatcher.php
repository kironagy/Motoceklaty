<?php

namespace App\Support;

/**
 * Typo tolerance for comparing a short AI-supplied query against a small
 * known set (catalog names/aliases). Re-implemented from the deleted
 * `HEAD:app/Support/FuzzyArabicMatcher.php` (git show), trimmed to what
 * T09's catalog search needs. Callers must normalize both strings with
 * ArabicTextNormalizer first - this only adds edit-distance tolerance.
 */
class FuzzyArabicMatcher
{
    /** True when every word of $needlePhrase has a fuzzy match somewhere in $haystack. */
    public function containsFuzzyPhrase(string $haystack, string $needlePhrase): bool
    {
        $needlePhrase = trim($needlePhrase);

        if ($needlePhrase === '') {
            return false;
        }

        if (str_contains($haystack, $needlePhrase)) {
            return true;
        }

        $haystackWords = preg_split('/\s+/u', trim($haystack), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $needleWords = preg_split('/\s+/u', $needlePhrase, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($haystackWords === [] || $needleWords === []) {
            return false;
        }

        foreach ($needleWords as $needleWord) {
            $matched = false;

            foreach ($haystackWords as $haystackWord) {
                if ($haystackWord === $needleWord || $this->wordsAreSimilar($haystackWord, $needleWord)) {
                    $matched = true;

                    break;
                }
            }

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * One dropped/added/swapped letter is tolerated, scaled a little for
     * longer words. Words shorter than 4 characters are excluded - fuzzy
     * matching short words across a large catalog produces false positives.
     */
    private function wordsAreSimilar(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $lenA = mb_strlen($a);
        $lenB = mb_strlen($b);

        if ($lenA < 4 || $lenB < 4 || abs($lenA - $lenB) > 1) {
            return false;
        }

        $maxDistance = max($lenA, $lenB) >= 7 ? 2 : 1;

        return $this->mbLevenshtein($a, $b) <= $maxDistance;
    }

    private function mbLevenshtein(string $a, string $b): int
    {
        $chars1 = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $chars2 = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $len1 = count($chars1);
        $len2 = count($chars2);
        $prev = range(0, $len2);

        for ($i = 1; $i <= $len1; $i++) {
            $curr = [$i];

            for ($j = 1; $j <= $len2; $j++) {
                $cost = $chars1[$i - 1] === $chars2[$j - 1] ? 0 : 1;
                $curr[$j] = min($prev[$j] + 1, $curr[$j - 1] + 1, $prev[$j - 1] + $cost);
            }

            $prev = $curr;
        }

        return $prev[$len2];
    }
}
