<?php

namespace App\Agent\Tools;

use App\Domain\Handoff\HandoffService;
use App\Models\WhatsappConversation;

/** WRITE — plan §6.18. Thin: all logic lives in HandoffService. */
class HandoffToHumanTool implements Tool
{
    public function __construct(private readonly HandoffService $handoffService)
    {
    }

    public function name(): string
    {
        return 'handoff_to_human';
    }

    public function description(): string
    {
        return 'Give the conversation to staff - the bot stops answering until they reply, so it is the LAST resort. '
            .'Use it right away only when: the customer asks for a person/support/the manager; a complaint about a '
            .'motorcycle he bought or money; he insists on negotiating the price/discount; the snapshot shows '
            .'IDENTITY_IN_USE_BY_ANOTHER_CUSTOMER. Otherwise handle it yourself: a question with no data on file, an '
            .'unclear message, a rejected document or an unusual job are NOT reasons to hand off - answer what you know, '
            .'ask a short question, or ask for the document again. You must still send_reply telling the customer a '
            .'colleague will follow up.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['reason', 'note'],
            'properties' => [
                'reason' => [
                    'type' => 'string',
                    'enum' => ['customer_request', 'complaint', 'document_unresolvable', 'out_of_scope', 'low_confidence', 'other'],
                ],
                'note' => ['type' => 'string', 'maxLength' => 300],
            ],
        ];
    }

    public function permission(): string
    {
        return 'WRITE';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $conversation = WhatsappConversation::findOrFail($ctx->conversationId);

        $this->handoffService->handOff($conversation, $args['reason'], $args['note'], source: 'ai');

        return ToolResult::ok(['handed_off' => true]);
    }
}
