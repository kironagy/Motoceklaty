<?php

namespace App\Support;

use App\Models\ApplicationData;
use App\Models\CustomerAttribute;
use App\Models\RequirementField;

/**
 * Keyed hash of an identity value (national ID) so duplicates across
 * customers can be found while the value itself stays encrypted.
 */
class IdentityLookup
{
    /** @return string[] field keys whose data_type is an identity number */
    public static function identityFieldKeys(): array
    {
        return RequirementField::where('data_type', 'national_id')->pluck('key')->all();
    }

    public static function hash(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', strtr((string) $value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));

        return $digits === '' ? null : hash_hmac('sha256', $digits, (string) config('app.key'));
    }

    /**
     * Customer ids other than $customerId holding the same identity value
     * (as a customer attribute, or as applicant data on their application).
     *
     * @return int[]
     */
    public static function otherCustomersWith(string $hash, int $customerId): array
    {
        $fromAttributes = CustomerAttribute::where('lookup_hash', $hash)
            ->where('customer_id', '!=', $customerId)
            ->pluck('customer_id');

        $fromApplications = ApplicationData::where('application_data.lookup_hash', $hash)
            ->where('application_data.party', 'applicant')
            ->join('applications', 'applications.id', '=', 'application_data.application_id')
            ->where('applications.customer_id', '!=', $customerId)
            ->pluck('applications.customer_id');

        return $fromAttributes->merge($fromApplications)->unique()->values()->all();
    }
}
