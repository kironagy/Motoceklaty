<?php

namespace App\Support;

/**
 * General-purpose typo tolerance for short Egyptian-Arabic customer
 * replies, used where we compare a short reply against a small known set
 * (variant keywords, already-shown machine names) - NOT a hardcoded list
 * of specific misspellings. A dropped/extra/swapped letter ("فز تاني"
 * for "فرز تاني") is common and unpredictable; the fix is tolerating
 * edit-distance-1 differences per word, not enumerating every typo
 * anyone might type.
 *
 * Deliberately separate from MachineSearchService's stricter internal
 * fuzzy matching (which requires word length >= 4 to avoid false
 * positives across a large catalog search). Here the candidate set is
 * always small and already filtered (a handful of shown machines, or a
 * short fixed keyword list), so a looser threshold is safe and worth it.
 */
class FuzzyArabicMatcher
{
    /**
     * True when every word of $needlePhrase has a fuzzy match somewhere
     * in $haystack. Both strings are expected to already be normalized
     * (same Arabic-letter/digit normalization used elsewhere in this
     * codebase) - this class only adds edit-distance tolerance on top.
     */
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

        if (empty($haystackWords) || empty($needleWords)) {
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

    public function containsAnyFuzzyPhrase(string $haystack, array $needlePhrases): bool
    {
        foreach ($needlePhrases as $needlePhrase) {
            if ($this->containsFuzzyPhrase($haystack, $needlePhrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One dropped/added/swapped letter is tolerated, scaled a little for
     * longer words. Very short words (<=1 char) are excluded - matching
     * single letters fuzzily produces noise, not typo tolerance.
     */
    private function wordsAreSimilar(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $lenA = mb_strlen($a);
        $lenB = mb_strlen($b);

        if ($lenA <= 1 || $lenB <= 1) {
            return false;
        }

        if (abs($lenA - $lenB) > 1) {
            return false;
        }

        $maxLen = max($lenA, $lenB);
        $maxDistance = $maxLen >= 7 ? 2 : 1;

        return $this->mbLevenshtein($a, $b) <= $maxDistance;
    }

    /**
     * levenshtein() built into PHP operates on bytes, not characters,
     * which breaks on multi-byte UTF-8 Arabic. This works on a real
     * character array instead.
     */
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

                $curr[$j] = min(
                    $prev[$j] + 1,
                    $curr[$j - 1] + 1,
                    $prev[$j - 1] + $cost
                );
            }

            $prev = $curr;
        }

        return $prev[$len2];
    }
}
