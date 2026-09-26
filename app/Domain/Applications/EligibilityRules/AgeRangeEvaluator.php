<?php

namespace App\Domain\Applications\EligibilityRules;

/**
 * params: {min, max}. Age comes from facts['age'] - derived from the
 * national ID's birth date by NationalIdValidator, or a directly stated
 * age fact. No hardcoded age numbers here (plan constraint) - min/max are
 * DEC-02's values, read from eligibility_rules.params. Optional
 * max_age_at_end caps the age at the last installment.
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

        // max_age_at_end: the last installment must fall by this age - a
        // 62-year-old may take 2 years, not 3 (owner, 2026-09-26). Checked
        // once a duration is known (facts['months']).
        $maxAtEnd = $params['max_age_at_end'] ?? null;
        $months = isset($facts['months']) ? (int) $facts['months'] : null;

        if ($maxAtEnd !== null && $months) {
            $maxMonths = max(0, ((int) $maxAtEnd - $age) * 12);

            if ($months > $maxMonths) {
                return EligibilityRuleResult::notEligible('AGE_AT_END_OF_PLAN', [
                    'age' => $age, 'max_age_at_end' => (int) $maxAtEnd, 'max_months' => $maxMonths,
                ]);
            }
        }

        return EligibilityRuleResult::eligible();
    }
}
