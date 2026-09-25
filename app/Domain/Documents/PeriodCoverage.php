<?php

namespace App\Domain\Documents;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\DocumentType;
use App\Support\ArabicTextNormalizer;
use Carbon\Carbon;

/**
 * Some documents prove something only together: "أرباح آخر ٣ شهور" often
 * arrives as one screenshot per month or per week, sent together or one by
 * one. A document type with a `period_coverage` rule
 * ({start_field, end_field, min_days, max_age_days}) keeps every accepted
 * screenshot (no superseding) and counts as accepted only once the union
 * of their periods reaches min_days and the latest one ends within
 * max_age_days of today. Overlaps and duplicates count once.
 */
class PeriodCoverage
{
    public static function ruleFor(?DocumentType $type): ?array
    {
        foreach ((array) ($type?->validation_rules ?? []) as $rule) {
            if (($rule['rule_type'] ?? null) === 'period_coverage') {
                return $rule['params'] ?? [];
            }
        }

        return null;
    }

    /** @return array{0: Carbon, 1: Carbon}|null */
    public static function period(array $params, array $extractedFields): ?array
    {
        // A weekly list sometimes comes back as every week's date in one
        // field ("2026-07-01, 2026-07-08, ..."): the period is simply the
        // earliest to the latest date found across both fields.
        $dates = array_merge(
            self::dates($extractedFields[$params['start_field']] ?? null),
            self::dates($extractedFields[$params['end_field']] ?? null),
        );

        if ($dates === []) {
            return null;
        }

        usort($dates, fn (Carbon $a, Carbon $b) => $a->timestamp <=> $b->timestamp);

        return [reset($dates), end($dates)];
    }

    /**
     * @return array{satisfied: bool, covered_days: int, needed_days: int, periods: string[], latest_end: ?string, too_old: bool}
     */
    public function summarize(Application $application, DocumentType $type): array
    {
        $params = self::ruleFor($type) ?? [];
        $needed = (int) ($params['min_days'] ?? 0);

        $periods = ApplicationDocument::where('application_id', $application->id)
            ->where('document_type_id', $type->id)
            ->where('status', 'accepted')
            ->get()
            ->map(fn (ApplicationDocument $d) => self::period($params, (array) $d->extracted))
            ->filter()
            ->sortBy(fn ($p) => $p[0]->timestamp)
            ->values();

        $merged = [];

        foreach ($periods as [$start, $end]) {
            $last = end($merged);

            if ($last && $start->lte($last[1]->copy()->addDay())) {
                $merged[array_key_last($merged)][1] = $end->gt($last[1]) ? $end : $last[1];
            } else {
                $merged[] = [$start, $end];
            }
        }

        $covered = array_sum(array_map(fn ($p) => (int) $p[0]->diffInDays($p[1]) + 1, $merged));
        $latestEnd = $merged === [] ? null : max(array_map(fn ($p) => $p[1], $merged));
        $tooOld = $latestEnd !== null && isset($params['max_age_days'])
            && $latestEnd->lt(now()->startOfDay()->subDays((int) $params['max_age_days']));

        return [
            'satisfied' => $merged !== [] && $covered >= $needed && ! $tooOld,
            'covered_days' => $covered,
            'needed_days' => $needed,
            'periods' => array_map(fn ($p) => $p[0]->toDateString().' → '.$p[1]->toDateString(), $merged),
            'latest_end' => $latestEnd?->toDateString(),
            'too_old' => $tooOld,
        ];
    }

    /** @return Carbon[] */
    private static function dates(mixed $value): array
    {
        $text = ArabicTextNormalizer::normalize((string) ($value ?? ''));

        if ($text === '') {
            return [];
        }

        preg_match_all('/\d{4}-\d{1,2}-\d{1,2}/', $text, $m);
        $candidates = $m[0] !== [] ? $m[0] : [$text];
        $dates = [];

        foreach ($candidates as $candidate) {
            try {
                $dates[] = Carbon::parse($candidate)->startOfDay();
            } catch (\Throwable) {
                // not a date - ignored
            }
        }

        return $dates;
    }
}
