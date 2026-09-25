<?php

namespace App\Domain\Installments;

/** $errorCode is a stable UPPER_SNAKE_CASE error code, surfaced as-is by tools (plan §3.1). */
class InstallmentCalculationException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
