<?php

namespace App\Domain\Applications\EligibilityRules;

final class EligibilityRuleResult
{
    /**
     * @param  array<int, array{code: string, params: array}>  $reasons
     * @param  string[]  $missingInputs
     */
    public function __construct(
        public readonly string $status, // eligible|not_eligible|unknown
        public readonly array $reasons = [],
        public readonly array $missingInputs = [],
    ) {
    }

    public static function eligible(): self
    {
        return new self('eligible');
    }

    public static function notEligible(string $code, array $params = []): self
    {
        return new self('not_eligible', [['code' => $code, 'params' => $params]]);
    }

    public static function unknown(array $missingInputs): self
    {
        return new self('unknown', [], $missingInputs);
    }
}
