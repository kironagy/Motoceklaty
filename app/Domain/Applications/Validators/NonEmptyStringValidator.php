<?php

namespace App\Domain\Applications\Validators;

use App\Models\RequirementField;

/**
 * Shared by string/person_name/address data types (plan T11 §2: "type/shape
 * checks only ... any stricter content rule needs a DEC-03 answer first").
 */
class NonEmptyStringValidator implements FieldValidator
{
    public function validate(string $value, RequirementField $field): FieldValidationResult
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return FieldValidationResult::invalid('INVALID_FORMAT');
        }

        return FieldValidationResult::ok($trimmed);
    }
}
