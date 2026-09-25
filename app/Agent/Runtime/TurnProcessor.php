<?php

namespace App\Agent\Runtime;

/**
 * Runs one claimed turn end to end (context, model calls, tools) and
 * returns the delivery result. T17 binds the real agent-runtime-driven
 * implementation; until then DisabledTurnProcessor throws so nothing can
 * be delivered from a half-built pipeline (plan principle 10).
 *
 * @see DisabledTurnProcessor
 */
interface TurnProcessor
{
    /**
     * @return array{messages: string[], quote_wa_message_id?: ?string, media?: array}
     */
    public function process(object $turn): array;
}
