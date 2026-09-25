<?php

namespace App\Domain\Documents\DocumentRules;

use App\Models\Application;
use Carbon\Carbon;

/** params: {date_field, max_age_days} - both configured per document_type, no hardcoded expiry window. */
class NotExpiredEvaluator implements DocumentRuleEvaluator
{
    public function evaluate(array $params, array $extractedFields, Application $application): ?array
    {
        $dateValue = $extractedFields[$params['date_field']] ?? null;

        if ($dateValue === null || ! isset($params['max_age_days'])) {
            return null;
        }

        try {
            $date = Carbon::parse($dateValue);
        } catch (\Throwable) {
            return null; // Malformed date is INVALID_FORMAT's job, not this rule's.
        }

        if ($date->diffInDays(now()) > (int) $params['max_age_days']) {
            return ['code' => 'EXPIRED_DOCUMENT', 'params' => ['max_age_days' => $params['max_age_days']]];
        }

        return null;
    }
}
