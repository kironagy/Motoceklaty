<?php

namespace Tests\Unit;

use App\Services\ApplicationStateService;
use App\Support\AddressParser;
use PHPUnit\Framework\TestCase;

/**
 * Conversation 254 looped on the full name forever. The turn that
 * rejected "احمد سيد" ALSO asked for the national ID, because a field
 * carrying a verification issue was dropped from the question list and
 * the next field in the list took its place. From the customer's side
 * the last thing asked was the national ID, so when they answered with
 * the corrected full name the extractor - which anchors on the last
 * question asked - read it as "not a national ID" and returned nothing.
 * The name never landed, the issue never cleared, and the same two
 * messages alternated until the customer gave up.
 *
 * A rejected field is not a field to skip past: it IS the open question.
 */
class ApplicationTurnQuestionTest extends TestCase
{
    private function service(): ApplicationStateService
    {
        return new ApplicationStateService(new AddressParser());
    }

    public function test_rejected_field_is_the_only_question_that_turn(): void
    {
        $missing = ['full_name', 'national_id', 'phone', 'home_address', 'installment_months'];
        $issues = ['full_name' => 'محتاج الاسم بالكامل زي ما هو مكتوب في البطاقة.'];

        $this->assertSame([], $this->service()->fieldsToAsk($missing, $issues));
    }

    public function test_without_issues_the_missing_fields_are_asked_normally(): void
    {
        $missing = ['full_name', 'national_id'];

        $this->assertSame($missing, $this->service()->fieldsToAsk($missing, []));
    }

    public function test_an_issue_on_a_field_that_is_not_missing_still_holds_the_turn(): void
    {
        // national_id was rejected but re-populated; the customer still
        // has to answer that rejection before we move the flow on.
        $missing = ['phone'];
        $issues = ['national_id' => 'الرقم القومي مش بيفك، ابعته تاني.'];

        $this->assertSame([], $this->service()->fieldsToAsk($missing, $issues));
    }
}
