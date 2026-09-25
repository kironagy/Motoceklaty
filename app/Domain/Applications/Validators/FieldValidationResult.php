<?php

namespace App\Domain\Applications\Validators;

final class FieldValidationResult
{
    /**
     * @param  array<string, mixed>  $facts  Derived facts (e.g. national_id -> birthdate/age) for eligibility.
     */
    public function __construct(
        public readonly bool $valid,
        public readonly mixed $normalized = null,
        public readonly ?string $errorCode = null,
        public readonly array $facts = [],
    ) {
    }

    public static function ok(mixed $normalized, array $facts = []): self
    {
        return new self(true, $normalized, null, $facts);
    }

    public static function invalid(string $errorCode): self
    {
        return new self(false, null, $errorCode);
    }
}
