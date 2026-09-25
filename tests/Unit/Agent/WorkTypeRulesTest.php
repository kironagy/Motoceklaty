<?php

namespace Tests\Unit\Agent;

use App\Domain\Applications\ConditionEvaluator;
use App\Domain\Documents\DocumentRules\NotPastEvaluator;
use App\Models\Application;
use PHPUnit\Framework\TestCase;

class WorkTypeRulesTest extends TestCase
{
    public function test_not_in_condition_needs_the_fact_to_be_known(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = ['fact' => 'work_type', 'op' => 'not_in', 'value' => ['delivery_app']];

        $this->assertFalse($evaluator->evaluate($condition, []));
        $this->assertFalse($evaluator->evaluate($condition, ['work_type' => 'delivery_app']));
        $this->assertTrue($evaluator->evaluate($condition, ['work_type' => 'craftsman']));
    }

    public function test_license_end_date_in_arabic_digits_is_checked_against_today(): void
    {
        $rule = new NotPastEvaluator();
        $params = ['date_field' => 'license_expiry_date', 'issue_code' => 'LICENSE_EXPIRED'];

        $this->assertNull($rule->evaluate($params, ['license_expiry_date' => '٢٠٩٩-٠٣-٠٦'], new Application()));
        $this->assertSame('LICENSE_EXPIRED', $rule->evaluate($params, ['license_expiry_date' => '2020-03-06'], new Application())['code']);
    }
}
