<?php

namespace App\Domain\Applications\EligibilityRules;

/**
 * params: excluded (male|female), message. Fact: gender - from the
 * national ID's own number (NationalIdValidator). Owner 2026-10-03:
 * "مفيش بنت بتقدم دخل حر" - the rule sits on the self_employed type. An
 * unknown gender blocks nothing: the card decides once it is read.
 */
class GenderEvaluator implements EligibilityRuleEvaluator
{
    public function evaluate(array $params, array $facts): EligibilityRuleResult
    {
        $gender = $facts['gender'] ?? null;

        if ($gender !== null && $gender === ($params['excluded'] ?? null)) {
            return EligibilityRuleResult::notEligible('GENDER_NOT_ACCEPTED_FOR_TYPE', ['message' => $params['message'] ?? null]);
        }

        return EligibilityRuleResult::eligible();
    }
}
