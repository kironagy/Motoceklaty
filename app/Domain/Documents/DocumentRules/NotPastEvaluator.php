<?php

namespace App\Domain\Documents\DocumentRules;

use App\Models\Application;
use App\Support\ArabicTextNormalizer;
use Carbon\Carbon;

/**
 * params: {date_field, issue_code?} - the extracted date (e.g. a driving
 * license's end date, "نهاية الترخيص") must not be before today. Unlike
 * not_expired, which limits how old an issue date may be.
 */
class NotPastEvaluator implements DocumentRuleEvaluator
{
    public function evaluate(array $params, array $extractedFields, Application $application): ?array
    {
        $dateValue = $extractedFields[$params['date_field']] ?? null;

        if ($dateValue === null || $dateValue === '') {
            return null;
        }

        try {
            $date = Carbon::parse(ArabicTextNormalizer::normalize((string) $dateValue));
        } catch (\Throwable) {
            return null;
        }

        if ($date->endOfDay()->isPast()) {
            return ['code' => $params['issue_code'] ?? 'EXPIRED_DOCUMENT', 'params' => ['date' => $date->toDateString()]];
        }

        return null;
    }
}
