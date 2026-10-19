<?php

namespace App\Domain\Forms;

class FormLinkException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = '')
    {
        parent::__construct($message ?: $errorCode);
    }
}
