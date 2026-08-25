<?php

namespace Tests\Unit;

use App\Support\EgyptianNationalId;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * The national ID carries the birth date inside it, so the flow can know
 * the applicant's age the moment they type the number - instead of
 * collecting every document first and only discovering at review time
 * that the applicant is 17.
 */
class EgyptianNationalIdTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Age assertions have to be stable regardless of when the suite runs.
        Carbon::setTestNow(Carbon::create(2026, 8, 26));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function parser(): EgyptianNationalId
    {
        return new EgyptianNationalId();
    }

    public function test_decodes_birthdate_governorate_and_gender(): void
    {
        // 2 90 05 13 21 0123 4 -> 1990-05-13, Giza (21), odd 13th digit = male
        $parsed = $this->parser()->parse('29005132101234');

        $this->assertTrue($parsed['valid']);
        $this->assertSame('1990-05-13', $parsed['birthdate']);
        $this->assertSame('الجيزة', $parsed['governorate']);
        $this->assertSame('male', $parsed['gender']);
        $this->assertSame(36, $parsed['age']);
        $this->assertTrue($parsed['age_ok']);
    }

    public function test_arabic_indic_digits_and_spacing_are_accepted(): void
    {
        $parsed = $this->parser()->parse('٢٩٠٠٥١٣٢١٠١٢٣٤');

        $this->assertTrue($parsed['valid']);
        $this->assertSame('29005132101234', $parsed['digits']);
    }

    public function test_applicant_under_twenty_one_is_flagged(): void
    {
        // Born 2010 -> 16 years old in 2026.
        $parsed = $this->parser()->parse('31003152101234');

        $this->assertTrue($parsed['valid']);
        $this->assertFalse($parsed['age_ok']);
        $this->assertStringContainsString('16', $this->parser()->problemMessage($parsed));
    }

    public function test_applicant_over_sixty_two_is_flagged(): void
    {
        // Born 1950 -> 76 years old in 2026.
        $parsed = $this->parser()->parse('25003152101234');

        $this->assertTrue($parsed['valid']);
        $this->assertFalse($parsed['age_ok']);
    }

    public function test_age_inside_the_window_has_no_problem_message(): void
    {
        $parsed = $this->parser()->parse('29005132101234');

        $this->assertNull($this->parser()->problemMessage($parsed));
    }

    /**
     * The whole point of decoding rather than only counting digits: a
     * fourteen-digit number that is not an ID must not pass.
     */
    public function test_fourteen_digits_with_impossible_date_is_rejected(): void
    {
        // Month 13 does not exist.
        $parsed = $this->parser()->parse('29013132101234');

        $this->assertFalse($parsed['valid']);
        $this->assertSame('bad_birthdate', $parsed['reason']);
    }

    public function test_unknown_governorate_code_is_rejected(): void
    {
        // "99" is not a registration governorate.
        $parsed = $this->parser()->parse('29005139901234');

        $this->assertFalse($parsed['valid']);
        $this->assertSame('bad_governorate', $parsed['reason']);
    }

    public function test_wrong_century_marker_is_rejected(): void
    {
        $parsed = $this->parser()->parse('99005132101234');

        $this->assertFalse($parsed['valid']);
        $this->assertSame('bad_century', $parsed['reason']);
    }

    public function test_short_and_long_numbers_are_rejected_with_distinct_reasons(): void
    {
        $this->assertSame('too_short', $this->parser()->parse('2900513210')['reason']);
        $this->assertSame('too_long', $this->parser()->parse('290051321012345678')['reason']);
    }

    public function test_female_thirteenth_digit_is_read_as_female(): void
    {
        $parsed = $this->parser()->parse('29005132101244');

        $this->assertSame('female', $parsed['gender']);
    }
}
