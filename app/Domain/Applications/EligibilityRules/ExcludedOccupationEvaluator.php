<?php

namespace App\Domain\Applications\EligibilityRules;

use App\Domain\Applications\OccupationPolicy;
use App\Support\ArabicTextNormalizer;

/** params: words (comma separated), message. Fact: work_statement - his own words about his work. */
class ExcludedOccupationEvaluator implements EligibilityRuleEvaluator
{
    public function evaluate(array $params, array $facts): EligibilityRuleResult
    {
        $text = ArabicTextNormalizer::normalize((string) ($facts['work_statement'] ?? ''));

        if ($text !== '' && OccupationPolicy::matches($text, OccupationPolicy::words($params))) {
            return EligibilityRuleResult::notEligible('OCCUPATION_NOT_ACCEPTED', ['message' => $params['message'] ?? null]);
        }

        return EligibilityRuleResult::eligible();
    }
}
