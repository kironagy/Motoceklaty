<?php

namespace App\Domain\Applications;

use App\Domain\Applications\EligibilityRules\EligibilityRuleRegistry;
use App\Models\EligibilityRule;

class EligibilityService
{
    public function __construct(private readonly EligibilityRuleRegistry $registry)
    {
    }

    /**
     * @return array{status: string, reasons: array, missing_inputs: string[]}
     */
    public function evaluate(array $facts, ?int $customerTypeId = null): array
    {
        $rules = EligibilityRule::query()
            ->where('is_active', true)
            ->where(function ($q) use ($customerTypeId) {
                $q->whereNull('customer_type_id');

                if ($customerTypeId !== null) {
                    $q->orWhere('customer_type_id', $customerTypeId);
                }
            })
            ->get();

        $reasons = [];
        $missingInputs = [];
        $hasUnknown = false;

        foreach ($rules as $rule) {
            $evaluator = $this->registry->for($rule->rule_type);

            if (! $evaluator) {
                continue;
            }

            $result = $evaluator->evaluate($rule->params, $facts);

            if ($result->status === 'not_eligible') {
                $reasons = array_merge($reasons, $result->reasons);
            } elseif ($result->status === 'unknown') {
                $hasUnknown = true;
                $missingInputs = array_merge($missingInputs, $result->missingInputs);
            }
        }

        if ($reasons !== []) {
            return ['status' => 'not_eligible', 'reasons' => $reasons, 'missing_inputs' => []];
        }

        if ($hasUnknown) {
            return ['status' => 'unknown', 'reasons' => [], 'missing_inputs' => array_values(array_unique($missingInputs))];
        }

        return ['status' => 'eligible', 'reasons' => [], 'missing_inputs' => []];
    }
}
