<?php

namespace App\Domain\Applications\Validators;

use App\Models\RequirementField;

class MoneyValidator implements FieldValidator
{
    public function validate(string $value, RequirementField $field): FieldValidationResult
    {
        $normalized = str_replace(',', '', trim($value));

        if (! is_numeric($normalized) || (float) $normalized < 0) {
            return FieldValidationResult::invalid('INVALID_FORMAT');
        }

        // Exposed as a fact under the field's own key, so eligibility rules
        // (e.g. minimum_value) can reference any money field generically -
        // mirrors NationalIdValidator's age/birthdate facts.
        return FieldValidationResult::ok((float) $normalized, [$field->key => (float) $normalized]);
    }
}
