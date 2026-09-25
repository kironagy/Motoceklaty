<?php

namespace App\Domain\Documents\DocumentRules;

use App\Models\Application;
use App\Support\ArabicTextNormalizer;
use Carbon\Carbon;

/**
 * params: {date_field, min_days, issue_code?} - at least min_days must have
 * passed since the extracted date (e.g. a salary slip's hire date for a
 * minimum length of service). The threshold is the owner's, set per
 * document type in the dashboard - none is assumed here.
 */
class MinDaysSinceEvaluator implements DocumentRuleEvaluator
{
    public function evaluate(array $params, array $extractedFields, Application $application): ?array
    {
        $dateValue = $extractedFields[$params['date_field'] ?? ''] ?? null;

        if ($dateValue === null || $dateValue === '' || ! isset($params['min_days'])) {
            return null;
        }

        try {
            $date = Carbon::parse(ArabicTextNormalizer::normalize((string) $dateValue))->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if ($date->gt(now()->startOfDay()->subDays((int) $params['min_days']))) {
            return ['code' => $params['issue_code'] ?? 'EMPLOYMENT_TOO_RECENT', 'params' => ['date' => $date->toDateString(), 'min_days' => (int) $params['min_days']]];
        }

        return null;
    }
}
