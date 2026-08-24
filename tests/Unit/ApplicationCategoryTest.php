<?php

namespace Tests\Unit;

use App\Services\Handlers\ApplicationHandler;
use PHPUnit\Framework\TestCase;

/**
 * Plan tasks 3.1 and 3.2: which job category a customer falls into decides
 * both the documents asked for and whether the installment flow may start
 * at all. Both are pure string logic, so they are covered here without a
 * database or a Gemini call.
 */
class ApplicationCategoryTest extends TestCase
{
    private ApplicationHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new ApplicationHandler();
    }

    /** @dataProvider categoryCases */
    public function test_job_types_map_to_the_right_income_category(string $jobType, string $expected): void
    {
        $this->assertSame($expected, $this->handler->categorizeIncome($jobType, ''));
    }

    public static function categoryCases(): array
    {
        return [
            'uber driver' => ['سواق اوبر', 'delivery'],
            'food delivery' => ['دليفري طلبات', 'delivery'],
            'indrive' => ['بشتغل على اندرايف', 'delivery'],
            'taxi owner' => ['صاحب تاكسي', 'taxi_owner'],
            'microbus' => ['سواق ميكروباص', 'taxi_owner'],
            'carpenter stays freelance' => ['نجار', 'freelance'],
            'employee' => ['موظف في شركة خاصة', 'employee'],
            'pension' => ['على المعاش', 'pension'],
            'business owner' => ['عندي محل', 'business'],
        ];
    }

    /** @dataProvider bannedCases */
    public function test_banned_professions_are_caught_across_spellings(string $jobType, bool $banned): void
    {
        $reason = $this->handler->bannedProfessionReason($jobType);

        $banned
            ? $this->assertNotNull($reason, "{$jobType} should be rejected")
            : $this->assertNull($reason, "{$jobType} should be allowed");
    }

    public static function bannedCases(): array
    {
        return [
            'lawyer' => ['محامي', true],
            'lawyer alif maqsura' => ['محامى', true],
            'law practice' => ['شغال بالمحاماة', true],
            'officer' => ['ضابط شرطة', true],
            'judge' => ['قاضى', true],
            'prosecution' => ['وكيل نيابة', true],
            'police clerk spaced' => ['أمين الشرطة', true],
            'interior deputy' => ['معاون في وزارة الداخلية', true],
            'storekeeper allowed' => ['أمين مخزن', false],
            'sales deputy allowed' => ['معاون مدير مبيعات', false],
            'carpenter allowed' => ['نجار', false],
            'bank employee allowed' => ['موظف بنك', false],
        ];
    }
}
