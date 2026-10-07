<?php

namespace App\Domain\Documents;

/**
 * A required paper and the papers the owner accepts in its place - never
 * fewer papers, only another proof of the same thing. Owner 2026-10-04: an
 * employee whose company issues no salary slip brings his social-insurance
 * print (برنت التأمينات); with neither, the card-only route is the last resort.
 */
final class DocumentEquivalents
{
    public const FOR = [
        'salary_slip' => ['insurance_print'],
        // Owner's note for a shop owner: no tax card / commercial register = the sign (outside photo,
        // required anyway) plus a photo of the inside of the shop.
        'tax_card' => ['business_place_inside_photo'],
    ];

    /** @param  string[]  $accepted  accepted document keys; returns them plus every requirement they satisfy */
    public static function satisfied(array $accepted): array
    {
        foreach (self::FOR as $required => $alternatives) {
            if (array_intersect($alternatives, $accepted) !== []) {
                $accepted[] = $required;
            }
        }

        return array_values(array_unique($accepted));
    }

    /** @param  string[]  $required  required keys; returns them plus the papers accepted in their place */
    public static function acceptable(array $required): array
    {
        foreach ($required as $key) {
            $required = array_merge($required, self::FOR[$key] ?? []);
        }

        return array_values(array_unique($required));
    }
}
