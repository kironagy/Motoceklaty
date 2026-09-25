<?php

namespace App\Domain\Installments;

/** A plan argument that cannot be used as given - never silently ignored. */
class PlanResolutionException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $detail = '')
    {
        parent::__construct($detail);
    }
}
