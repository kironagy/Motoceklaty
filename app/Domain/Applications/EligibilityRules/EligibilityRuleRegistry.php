<?php

namespace App\Domain\Applications\EligibilityRules;

class EligibilityRuleRegistry
{
    /** @var array<string, EligibilityRuleEvaluator> */
    private array $evaluators = [];

    public function __construct()
    {
        foreach (config('agent.eligibility_rules', []) as $ruleType => $class) {
            $this->evaluators[$ruleType] = app($class);
        }
    }

    public function for(string $ruleType): ?EligibilityRuleEvaluator
    {
        return $this->evaluators[$ruleType] ?? null;
    }
}
