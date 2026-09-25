<?php

namespace Tests\Feature\Agent;

use App\Domain\Applications\ConditionEvaluator;
use App\Domain\Applications\EligibilityService;
use App\Models\EligibilityRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_condition_evaluation_operators(): void
    {
        $evaluator = new ConditionEvaluator();
        $facts = ['selection' => ['financed_amount' => 50000]];

        $this->assertTrue($evaluator->evaluate(['fact' => 'selection.financed_amount', 'op' => 'gt', 'value' => 10000], $facts));
        $this->assertFalse($evaluator->evaluate(['fact' => 'selection.financed_amount', 'op' => 'lt', 'value' => 10000], $facts));
        $this->assertTrue($evaluator->evaluate(['fact' => 'selection.financed_amount', 'op' => 'eq', 'value' => 50000], $facts));
        $this->assertTrue($evaluator->evaluate(['fact' => 'selection.financed_amount', 'op' => 'in', 'value' => [50000, 60000]], $facts));
        $this->assertTrue($evaluator->evaluate(null, $facts));
    }

    public function test_condition_with_missing_fact_is_false(): void
    {
        $evaluator = new ConditionEvaluator();

        $this->assertFalse($evaluator->evaluate(['fact' => 'selection.down_payment', 'op' => 'gt', 'value' => 0], []));
    }

    public function test_age_range_below_inside_above_and_unknown(): void
    {
        config(['agent.eligibility_rules' => ['age_range' => \App\Domain\Applications\EligibilityRules\AgeRangeEvaluator::class]]);

        EligibilityRule::create(['customer_type_id' => null, 'rule_type' => 'age_range', 'params' => ['min' => 21, 'max' => 60], 'is_active' => true]);

        $service = app(EligibilityService::class);

        $below = $service->evaluate(['age' => 18]);
        $this->assertSame('not_eligible', $below['status']);
        $this->assertSame('AGE_OUT_OF_RANGE', $below['reasons'][0]['code']);

        $inside = $service->evaluate(['age' => 30]);
        $this->assertSame('eligible', $inside['status']);

        $above = $service->evaluate(['age' => 70]);
        $this->assertSame('not_eligible', $above['status']);

        $unknown = $service->evaluate([]);
        $this->assertSame('unknown', $unknown['status']);
        $this->assertSame(['age'], $unknown['missing_inputs']);
    }

    public function test_minimum_value_below_at_above_and_unknown(): void
    {
        config(['agent.eligibility_rules' => ['minimum_value' => \App\Domain\Applications\EligibilityRules\MinimumValueEvaluator::class]]);

        EligibilityRule::create(['customer_type_id' => null, 'rule_type' => 'minimum_value', 'params' => ['fact' => 'monthly_income', 'min' => 4000], 'is_active' => true]);

        $service = app(EligibilityService::class);

        $below = $service->evaluate(['monthly_income' => 3000]);
        $this->assertSame('not_eligible', $below['status']);
        $this->assertSame('BELOW_MINIMUM_VALUE', $below['reasons'][0]['code']);

        $atMin = $service->evaluate(['monthly_income' => 4000]);
        $this->assertSame('eligible', $atMin['status']);

        $above = $service->evaluate(['monthly_income' => 5000]);
        $this->assertSame('eligible', $above['status']);

        $unknown = $service->evaluate([]);
        $this->assertSame('unknown', $unknown['status']);
        $this->assertSame(['monthly_income'], $unknown['missing_inputs']);
    }

    public function test_rule_scoped_to_a_specific_customer_type_does_not_apply_to_others(): void
    {
        config(['agent.eligibility_rules' => ['age_range' => \App\Domain\Applications\EligibilityRules\AgeRangeEvaluator::class]]);

        $type = \App\Models\CustomerType::create(['key' => 'self_employed', 'label' => 'Self Employed']);
        EligibilityRule::create(['customer_type_id' => $type->id, 'rule_type' => 'age_range', 'params' => ['min' => 25, 'max' => 50], 'is_active' => true]);

        $service = app(EligibilityService::class);

        // No customer type given -> the scoped rule does not apply.
        $result = $service->evaluate(['age' => 20], null);
        $this->assertSame('eligible', $result['status']);

        $scoped = $service->evaluate(['age' => 20], $type->id);
        $this->assertSame('not_eligible', $scoped['status']);
    }
}
