<?php

namespace App\Agent\Runtime;

class DisabledTurnProcessor implements TurnProcessor
{
    public function process(object $turn): array
    {
        throw new \RuntimeException(
            'DisabledTurnProcessor: the agent runtime is not wired yet (see T17). Nothing may be delivered.'
        );
    }
}
