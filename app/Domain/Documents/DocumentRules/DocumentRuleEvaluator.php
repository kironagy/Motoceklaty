<?php

namespace App\Domain\Documents\DocumentRules;

use App\Models\Application;

interface DocumentRuleEvaluator
{
    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $extractedFields
     * @return array{code: string, params: array}|null  null when the rule passes
     */
    public function evaluate(array $params, array $extractedFields, Application $application): ?array;
}
