<?php

namespace App\Domain\Applications\Validators;

use App\Models\RequirementField;
use App\Support\EgyptianNationalId;

class NationalIdValidator implements FieldValidator
{
    public function __construct(private readonly EgyptianNationalId $parser = new EgyptianNationalId())
    {
    }

    public function validate(string $value, RequirementField $field): FieldValidationResult
    {
        $parsed = $this->parser->parse($value);

        if (! $parsed['valid']) {
            return FieldValidationResult::invalid(strtoupper($parsed['reason'] ?? 'INVALID_FORMAT'));
        }

        return FieldValidationResult::ok($parsed['digits'], [
            'birthdate' => $parsed['birthdate'],
            'age' => $parsed['age'],
            'governorate' => $parsed['governorate'],
            'gender' => $parsed['gender'],
        ]);
    }
}
