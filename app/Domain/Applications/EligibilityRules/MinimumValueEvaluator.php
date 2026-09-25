<?php

namespace App\Domain\Applications\EligibilityRules;

/**
 * Generic "fact must be at least min" check - e.g. the pension minimum
 * income rule (ai_memories#44 on the old production system: pension income
 * must not be under 4000 EGP). params: {fact, min}. No hardcoded numbers
 * here; both come from eligibility_rules.params, same pattern as
 * AgeRangeEvaluator/FinancingCapEvaluator.
 */
class MinimumValueEvaluator implements EligibilityRuleEvaluator
{
    public function evaluate(array $params, array $facts): EligibilityRuleResult
    {
        $factKey = $params['fact'];

        if (! array_key_exists($factKey, $facts) || $facts[$factKey] === null) {
            return EligibilityRuleResult::unknown([$factKey]);
        }

        $value = (float) $facts[$factKey];
        $min = (float) $params['min'];

        if ($value < $min) {
            return EligibilityRuleResult::notEligible('BELOW_MINIMUM_VALUE', ['fact' => $factKey, 'min' => $min, 'value' => $value]);
        }

        return EligibilityRuleResult::eligible();
    }
}
