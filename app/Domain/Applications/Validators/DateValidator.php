<?php

namespace App\Domain\Applications\Validators;

use App\Models\RequirementField;
use App\Support\ArabicTextNormalizer;
use Carbon\Carbon;

class DateValidator implements FieldValidator
{
    public function validate(string $value, RequirementField $field): FieldValidationResult
    {
        try {
            $date = Carbon::parse(ArabicTextNormalizer::normalize($value));
        } catch (\Throwable) {
            return FieldValidationResult::invalid('INVALID_FORMAT');
        }

        return FieldValidationResult::ok($date->toDateString());
    }
}
