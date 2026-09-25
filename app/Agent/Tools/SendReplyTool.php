<?php

namespace App\Agent\Tools;

use App\Models\Machine;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;

/**
 * WRITE (outbound, ends the turn) — plan §6.19. The only way a turn ends
 * normally. Conversational delivery only: never touches applications,
 * customer data, documents, selections or handoff state.
 */
class SendReplyTool implements Tool
{
    public function name(): string
    {
        return 'send_reply';
    }

    public function description(): string
    {
        return 'Deliver the reply to the customer. This is the only way a turn ends normally. '
            .'awaiting is guidance for the next turn, not a step machine - application truth always '
            .'comes from the application snapshot.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'messages' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 3,
                    'items' => ['type' => 'string', 'maxLength' => (int) config('agent.reply.max_chars', 700)],
                ],
                'quote_wa_message_id' => ['type' => 'string'],
                'focus_motorcycle_ids' => [
                    'type' => 'array',
                    'minItems' => 0,
                    'maxItems' => 3,
                    'items' => ['type' => 'integer'],
                ],
                'awaiting' => [
                    'type' => 'array',
                    'minItems' => 0,
                    'maxItems' => 5,
                    'items' => [
                        'type' => 'object',
                        'required' => ['kind', 'key'],
                        'properties' => [
                            'kind' => ['type' => 'string', 'enum' => ['field', 'document', 'confirmation']],
                            'key' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'required' => ['messages'],
        ];
    }

    public function permission(): string
    {
        return 'WRITE';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $conversation = WhatsappConversation::findOrFail($ctx->conversationId);

        $focusIds = $args['focus_motorcycle_ids'] ?? [];

        if ($focusIds !== []) {
            $found = Machine::query()->whereIn('id', $focusIds)->pluck('id')->all();
            $unknown = array_diff($focusIds, $found);

            if ($unknown !== []) {
                return ToolResult::error('UNKNOWN_FOCUS_ID', 'Unknown motorcycle id(s): '.implode(', ', $unknown));
            }
        }

        $awaiting = $args['awaiting'] ?? [];

        foreach ($awaiting as $item) {
            // T11's field registry / document types aren't built yet; until
            // then only a bare confirmation is accepted (plan T06 §6).
            if ($item['kind'] !== 'confirmation') {
                return ToolResult::error('UNKNOWN_AWAITING_KEY', "Unsupported awaiting kind before T11: {$item['kind']}");
            }
        }

        if (! empty($args['quote_wa_message_id'])) {
            $quoteExists = WhatsappMessage::query()
                ->where('whatsapp_conversation_id', $ctx->conversationId)
                ->where('wa_message_id', $args['quote_wa_message_id'])
                ->exists();

            if (! $quoteExists) {
                return ToolResult::error('QUOTE_NOT_FOUND', 'The quoted message does not belong to this conversation.');
            }
        }

        $ctx->outbound->addMessages($args['messages']);
        $ctx->outbound->setQuote($args['quote_wa_message_id'] ?? null);
        $ctx->outbound->setFocus($focusIds);
        $ctx->outbound->finish();

        $state = $conversation->state ?? [];
        $state['focus_motorcycle_ids'] = $focusIds;
        $state['awaiting'] = array_map(
            fn ($item) => ['kind' => $item['kind'], 'key' => $item['key'], 'asked_at' => now()->toIso8601String()],
            $awaiting
        );

        $conversation->state = $state;
        $conversation->save();

        return ToolResult::ok(['accepted' => true]);
    }
}
