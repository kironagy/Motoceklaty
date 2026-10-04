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

        // A colleague answered after the customer's last message (the turn
        // waited out his quiet window): nothing new to answer - the bot
        // carries on from the customer's next message.
        if (self::staffAnsweredLast((int) $turn->whatsapp_conversation_id)) {
            return ['messages' => []];
        }

        return $this->runner->run($turn);
    }

    public static function staffAnsweredLast(int $conversationId): bool
    {
        $last = \App\Models\WhatsappMessage::where('whatsapp_conversation_id', $conversationId)
            ->where(fn ($q) => $q->where('direction', 'incoming')->orWhereIn('sender_type', ['agent', 'human_phone']))
            ->latest('id')
            ->first(['direction', 'sender_type']);

        return $last !== null && $last->direction === 'outgoing';
    }
}
