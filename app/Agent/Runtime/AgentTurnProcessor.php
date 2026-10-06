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
        // A colleague answered after the customer's last message (the turn
        // waited out his quiet window): nothing new to answer - the bot
        // carries on from the customer's next message.
        if (self::staffAnsweredLast((int) $turn->whatsapp_conversation_id)) {
            return ['messages' => []];
        }

        // OBS-001: every model call made for this turn - however deep - is counted against it.
        return \App\Agent\Tracing\AiCalls::within(
            ['turn_id' => (int) $turn->id, 'conversation_id' => (int) $turn->whatsapp_conversation_id, 'runner' => 'v1'],
            fn () => $this->runner->run($turn),
        );
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
