<?php

namespace App\Agent\Runtime;

use App\Domain\Conversations\ConversationClosing;

/** T17 §4: the real TurnProcessor, bound only when config('agent.enabled') is true. */
class AgentTurnProcessor implements TurnProcessor
{
    public function __construct(private readonly AgentRunner $runner)
    {
    }

    public function process(object $turn): array
    {
        // We already said goodbye and he only wrote "تسلم" / "حبيبي": no reply.
        if (ConversationClosing::onlyThanksAfterGoodbye((int) $turn->whatsapp_conversation_id, (int) $turn->id)) {
            return ['messages' => []];
        }

        return $this->runner->run($turn);
    }
}
