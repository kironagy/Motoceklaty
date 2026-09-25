<?php

namespace App\Domain\Documents\DocumentRules;

class DocumentRuleRegistry
{
    /** @var array<string, DocumentRuleEvaluator> */
    private array $evaluators = [];

    public function __construct()
    {
        foreach (config('agent.document_rules', []) as $ruleType => $class) {
            $this->evaluators[$ruleType] = app($class);
        }
    }

    public function for(string $ruleType): ?DocumentRuleEvaluator
    {
        return $this->evaluators[$ruleType] ?? null;
    }
}
