<?php

namespace App\Support;

/**
 * Names in the legacy requests carry no hamza (أ إ آ -> ا): the dashboard's
 * search and the owner's lists were built that way. Everything that writes
 * applicant_name / guarantor_name folds through here.
 */
final class ArabicName
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return str_replace(['أ', 'إ', 'آ'], 'ا', $value);
    }
}
