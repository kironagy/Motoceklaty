<?php

namespace App\Domain\Applications\EligibilityRules;

/**
 * params: {min, max}. Age comes from facts['age'] - derived from the
 * national ID's birth date by NationalIdValidator, or a directly stated
 * age fact. No hardcoded age numbers here (plan constraint) - min/max are
 * DEC-02's values, read from eligibility_rules.params.
 */
class AgeRangeEvaluator implements EligibilityRuleEvaluator
{
    public function evaluate(array $params, array $facts): EligibilityRuleResult
    {
        if (! array_key_exists('age', $facts) || $facts['age'] === null) {
            return EligibilityRuleResult::unknown(['age']);
        }

        $age = (int) $facts['age'];
        $min = $params['min'];
        $max = $params['max'];

        if ($age < $min || $age > $max) {
            return EligibilityRuleResult::notEligible('AGE_OUT_OF_RANGE', ['min' => $min, 'max' => $max, 'age' => $age]);
        }

        return EligibilityRuleResult::eligible();
    }
}
