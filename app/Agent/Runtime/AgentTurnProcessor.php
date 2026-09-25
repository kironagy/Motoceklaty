<?php

namespace App\Agent\Runtime;

/** T17 §4: the real TurnProcessor, bound only when config('agent.enabled') is true. */
class AgentTurnProcessor implements TurnProcessor
{
    public function __construct(private readonly AgentRunner $runner)
    {
    }

    public function process(object $turn): array
    {
        return $this->runner->run($turn);
    }
}
