<?php

namespace Tests\Unit;

use App\Services\ApplicantDataVerifier;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * في اختبار حقيقي العميل كتب "سني ٢٨"، وبعدين بعت رقم قومي بيطلع منه سن
 * ٤١، والطلب عدّى عادي من غير ما حد يعلّق - وكان هيوصل للمراجعة اليدوية
 * بتناقض واضح بعد ما العميل يرفع كل الورق.
 *
 * التناقض ده معناه إما رقم مكتوب غلط أو رقم مش بتاعه، والاتنين لازم
 * يتسألوا في نفس اللحظة.
 */
class StatedAgeContradictionTest extends TestCase
{
    private function issue(array $application): ?string
    {
        $verifier = app(ApplicantDataVerifier::class);

        $method = new ReflectionMethod($verifier, 'ageContradiction');
        $method->setAccessible(true);

        return $method->invoke($verifier, $application);
    }

    public function test_a_big_gap_between_stated_and_derived_age_is_flagged(): void
    {
        $issue = $this->issue(['stated_age' => 28, 'age' => 41]);

        $this->assertNotNull($issue);
        $this->assertStringContainsString('28', $issue);
        $this->assertStringContainsString('41', $issue);
    }

    /** العميل بيقرّب سنه في الكلام العادي - مش هنوقف طلب عشان سنتين. */
    public function test_a_two_year_gap_is_tolerated(): void
    {
        $this->assertNull($this->issue(['stated_age' => 28, 'age' => 30]));
        $this->assertNull($this->issue(['stated_age' => 30, 'age' => 28]));
    }

    public function test_an_exact_match_is_not_flagged(): void
    {
        $this->assertNull($this->issue(['stated_age' => 35, 'age' => 35]));
    }

    public function test_nothing_is_flagged_when_the_customer_never_stated_an_age(): void
    {
        $this->assertNull($this->issue(['age' => 41]));
    }

    public function test_nothing_is_flagged_before_the_national_id_arrives(): void
    {
        $this->assertNull($this->issue(['stated_age' => 28]));
    }

    /** رقم مستحيل في الرسالة ("عندي ٥ سنين") مش تناقض - ده سوء استخراج. */
    public function test_an_impossible_stated_age_is_ignored(): void
    {
        $this->assertNull($this->issue(['stated_age' => 5, 'age' => 41]));
        $this->assertNull($this->issue(['stated_age' => 120, 'age' => 41]));
    }

    public function test_string_values_from_the_extractor_are_handled(): void
    {
        $this->assertNotNull($this->issue(['stated_age' => '28', 'age' => '41']));
    }
}
