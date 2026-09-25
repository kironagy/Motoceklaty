<?php

namespace App\Domain\Applications\Validators;

use App\Models\RequirementField;

/**
 * DEC-24: Egyptian mobile only (010/011/012/015 + 8 digits), country code
 * +20/20 optional - with or without the leading 0 that the country code
 * normally replaces (+201012345678 and +2001012345678 both accepted).
 * Normalized to the local 11-digit form (01XXXXXXXXX).
 */
class PhoneValidator implements FieldValidator
{
    private const PATTERN = '/^(?:\+?20)?0?(1[0125]\d{8})$/';

    public function validate(string $value, RequirementField $field): FieldValidationResult
    {
        $digits = preg_replace('/[\s\-]+/', '', trim($value));

        if (! preg_match(self::PATTERN, $digits, $matches)) {
            return FieldValidationResult::invalid('INVALID_FORMAT');
        }

        return FieldValidationResult::ok('0'.$matches[1]);
    }
}
