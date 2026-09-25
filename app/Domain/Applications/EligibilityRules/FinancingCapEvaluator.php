<?php

namespace App\Domain\Applications\EligibilityRules;

/**
 * DEC-02: a financing-cap rule type (e.g. the self-employed cap), added to
 * the T11 registry per T12 scope item 3. params: {max_amount} - no
 * hardcoded cap value here; the owner sets it per customer_type from the
 * dashboard (eligibility_rules.params). facts['financed_amount'] is
 * supplied by check_eligibility via InstallmentCalculator when a
 * motorcycle and plan are given (plan T12 §5); without it the rule cannot
 * be evaluated.
 *
 * Whether exceeding the cap blocks the application outright or instead
 * requires a bigger down payment is a conversational/business decision for
 * T16/T17 to act on using this reason code - this evaluator only reports
 * the deterministic fact.
 */
class FinancingCapEvaluator implements EligibilityRuleEvaluator
{
    public function evaluate(array $params, array $facts): EligibilityRuleResult
    {
        if (! array_key_exists('financed_amount', $facts) || $facts['financed_amount'] === null) {
            return EligibilityRuleResult::unknown(['financed_amount']);
        }

        $financedAmount = (float) $facts['financed_amount'];
        $maxAmount = (float) $params['max_amount'];

        if ($financedAmount > $maxAmount) {
            return EligibilityRuleResult::notEligible('FINANCING_CAP_EXCEEDED', [
                'max_amount' => $maxAmount,
                'financed_amount' => $financedAmount,
            ]);
        }

        return EligibilityRuleResult::eligible();
    }
}
