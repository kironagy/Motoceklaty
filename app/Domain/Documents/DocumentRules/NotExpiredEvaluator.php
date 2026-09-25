<?php

namespace App\Domain\Documents\DocumentRules;

use App\Models\Application;
use App\Support\ArabicTextNormalizer;
use Carbon\Carbon;

/** params: {date_field, max_age_days} - both configured per document_type, no hardcoded expiry window. */
class NotExpiredEvaluator implements DocumentRuleEvaluator
{
    public function evaluate(array $params, array $extractedFields, Application $application): ?array
    {
        $dateValue = $extractedFields[$params['date_field']] ?? null;

        if ($dateValue === null || $dateValue === '' || ! isset($params['max_age_days'])) {
            return null;
        }

        try {
            $date = Carbon::parse(ArabicTextNormalizer::normalize((string) $dateValue));
        } catch (\Throwable) {
            return null; // Malformed date is INVALID_FORMAT's job, not this rule's.
        }

        if ($date->diffInDays(now()) > (int) $params['max_age_days']) {
            return ['code' => $params['issue_code'] ?? 'EXPIRED_DOCUMENT', 'params' => ['max_age_days' => $params['max_age_days'], 'date' => $date->toDateString()]];
        }

        return null;
    }
}
