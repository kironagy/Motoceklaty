<?php

namespace App\Domain\Applications;

use App\Models\Application;
use App\Models\ApplicationDocument;

/**
 * The job printed on the back of his national ID (owner 2026-10-04): a job
 * title ("مهندس بشركة كذا") makes the salary slip compulsory; no job
 * ("بدون عمل", "طالب") is no problem - he applies with his ID, no salary
 * slip asked of him.
 */
final class IdOccupation
{
    private const NO_JOB = '/بدون\s*عمل|بدون|لا\s*يعمل|لايعمل|عاطل|طالب|طالبه|طالبة|ربة\s*منزل|ربه\s*منزل|غير\s*مبين|لا\s*يوجد/u';

    /** The occupation read off his accepted ID back, or null when it was not read. */
    public static function of(Application $application): ?string
    {
        $documents = ApplicationDocument::where('application_id', $application->id)
            ->where('status', 'accepted')
            ->whereHas('documentType', fn ($q) => $q->where('key', 'national_id_back'))
            ->latest('id')
            ->get();

        foreach ($documents as $document) {
            $occupation = trim((string) (($document->extracted ?? [])['occupation'] ?? ''));

            if ($occupation !== '') {
                return $occupation;
            }
        }

        return null;
    }

    public static function isNoJob(?string $occupation): bool
    {
        return $occupation !== null && preg_match(self::NO_JOB, $occupation) === 1;
    }
}
