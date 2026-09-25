<?php

namespace App\Domain\Applications\EligibilityRules;

interface EligibilityRuleEvaluator
{
    public function evaluate(array $params, array $facts): EligibilityRuleResult;
}
