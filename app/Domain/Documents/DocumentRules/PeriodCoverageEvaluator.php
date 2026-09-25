<?php

namespace App\Domain\Documents\DocumentRules;

use App\Domain\Documents\PeriodCoverage;
use App\Models\Application;

/**
 * Per-screenshot half of `period_coverage`: the period must be readable
 * and not in the future. Whether all screenshots together cover enough
 * time is decided across documents by PeriodCoverage in the snapshot.
 */
class PeriodCoverageEvaluator implements DocumentRuleEvaluator
{
    public function evaluate(array $params, array $extractedFields, Application $application): ?array
    {
        $period = PeriodCoverage::period($params, $extractedFields);

        if (! $period) {
            return ['code' => 'PERIOD_UNREADABLE', 'params' => []];
        }

        if ($period[0]->gt(now()->endOfDay())) {
            return ['code' => 'PERIOD_IN_FUTURE', 'params' => []];
        }

        return null;
    }
}
