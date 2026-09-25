<?php

namespace App\Domain\Applications\Validators;

use App\Models\RequirementField;
use Carbon\Carbon;

class DateValidator implements FieldValidator
{
    public function validate(string $value, RequirementField $field): FieldValidationResult
    {
        try {
            $date = Carbon::parse(trim($value));
        } catch (\Throwable) {
            return FieldValidationResult::invalid('INVALID_FORMAT');
        }

        return FieldValidationResult::ok($date->toDateString());
    }
}
