<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Support\FuzzyArabicMatcher;

/**
 * Covers the real customer typo reported in production: "فز تاني"
 * (missing ر) failed to narrow down "... استيراد فرز تاني" among
 * several shown machines, because the old matching was exact-substring
 * only. This tolerates a one-letter typo instead of requiring every
 * misspelling to be hardcoded.
 */
class FuzzyArabicMatcherTest extends TestCase
{
    private function matcher(): FuzzyArabicMatcher
    {
        return new FuzzyArabicMatcher();
    }

    public function test_missing_letter_typo_matches_full_phrase(): void
    {
        $matcher = $this->matcher();

        $this->assertTrue($matcher->containsFuzzyPhrase(
            'هوجان هوجن 4 استيراد فز تاني',
            'فرز تاني'
        ));
    }

    public function test_customer_reply_with_typo_matches_keyword_list(): void
    {
        $matcher = $this->matcher();

        $this->assertTrue($matcher->containsAnyFuzzyPhrase('فز تاني', [
            'استيراد',
            'فرز تاني',
            'فرز ثاني',
        ]));
    }

    public function test_exact_phrase_still_matches(): void
    {
        $matcher = $this->matcher();

        $this->assertTrue($matcher->containsFuzzyPhrase(
            'هوجان هوجن 4 استيراد فرز تاني',
            'فرز تاني'
        ));
    }

    public function test_unrelated_text_does_not_match(): void
    {
        $matcher = $this->matcher();

        $this->assertFalse($matcher->containsFuzzyPhrase('عايز اعرف السعر', 'فرز تاني'));
    }

    public function test_very_short_words_are_not_fuzzy_matched(): void
    {
        $matcher = $this->matcher();

        // Single-letter/very-short tokens should require an exact match,
        // not fuzzy tolerance, to avoid noisy false positives.
        $this->assertFalse($matcher->containsFuzzyPhrase('في المعرض', 'من'));
    }
}
