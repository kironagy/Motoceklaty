<?php

namespace App\Domain\Applications\Validators;

use App\Models\RequirementField;

interface FieldValidator
{
    public function validate(string $value, RequirementField $field): FieldValidationResult;
}
