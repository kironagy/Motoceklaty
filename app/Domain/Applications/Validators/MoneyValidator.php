<?php

namespace App\Domain\Applications\Validators;

use App\Models\RequirementField;
use App\Support\ArabicTextNormalizer;

class MoneyValidator implements FieldValidator
{
    public function validate(string $value, RequirementField $field): FieldValidationResult
    {
        // "٥٬٤٣٢٫٥٠ جنيه" off a salary slip, "4500ج" typed by the customer:
        // Arabic digits and separators, the currency around the number.
        $normalized = ArabicTextNormalizer::normalize(str_replace(['٫', '٬'], ['.', ','], $value));
        $normalized = trim(preg_replace('/\s*(?:جنيه|جنية|جنيها|ج\.?\s?م\.?|ج|egp|le|l\.e\.?)\s*$/u', '', $normalized));
        $normalized = str_replace([',', ' '], '', $normalized);

        if (! is_numeric($normalized) || (float) $normalized < 0) {
            return FieldValidationResult::invalid('INVALID_FORMAT');
        }

        // Exposed as a fact under the field's own key, so eligibility rules
        // (e.g. minimum_value) can reference any money field generically -
        // mirrors NationalIdValidator's age/birthdate facts.
        return FieldValidationResult::ok((float) $normalized, [$field->key => (float) $normalized]);
    }
}
