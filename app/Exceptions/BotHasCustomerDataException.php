<?php

namespace App\Exceptions;

/** Deleting a bot that has customers/conversations would cascade-delete their data. */
class BotHasCustomerDataException extends \RuntimeException
{
    public function __construct(public readonly int $botId)
    {
        parent::__construct("WhatsApp bot {$botId} has customers and conversations - deactivate it instead of deleting it.");
    }
}
