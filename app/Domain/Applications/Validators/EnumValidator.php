<?php

namespace App\Domain\Applications\Validators;

use App\Models\RequirementField;

class EnumValidator implements FieldValidator
{
    public function validate(string $value, RequirementField $field): FieldValidationResult
    {
        $options = $field->enum_options ?? [];

        if (! in_array($value, $options, true)) {
            return FieldValidationResult::invalid('INVALID_FORMAT');
        }

        // Exposed as a fact so a requirement can depend on it (e.g. work_type
        // = delivery_app makes the driving license required).
        return FieldValidationResult::ok($value, [$field->key => $value]);
    }
}
