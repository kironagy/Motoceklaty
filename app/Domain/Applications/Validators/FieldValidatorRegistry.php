<?php

namespace App\Domain\Applications\Validators;

class FieldValidatorRegistry
{
    /** @var array<string, FieldValidator> */
    private array $validators = [];

    public function __construct()
    {
        foreach (config('agent.field_validators', []) as $dataType => $class) {
            $this->validators[$dataType] = app($class);
        }
    }

    public function for(string $dataType): ?FieldValidator
    {
        return $this->validators[$dataType] ?? null;
    }
}
