<?php

namespace App\Domain\Applications\Validators;

use App\Models\RequirementField;

class IntegerValidator implements FieldValidator
{
    public function validate(string $value, RequirementField $field): FieldValidationResult
    {
        $trimmed = trim($value);

        if (! preg_match('/^-?\d+$/', $trimmed)) {
            return FieldValidationResult::invalid('INVALID_FORMAT');
        }

        return FieldValidationResult::ok((int) $trimmed);
    }
}
