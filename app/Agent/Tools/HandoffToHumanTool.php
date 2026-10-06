<?php

namespace App\Agent\Tools;

use App\Domain\Handoff\HandoffService;
use App\Models\WhatsappConversation;

/** WRITE — plan §6.18. Thin: all logic lives in HandoffService. */
class HandoffToHumanTool implements WriteTool
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
        return 'Give the conversation to staff (the bot then stops, except call_request). Only when: he asks for a person or a call, '
            .'a complaint about a bike or money, he insists on a discount, IDENTITY_IN_USE_BY_ANOTHER_CUSTOMER, or he insists on information we do not have.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['reason', 'note'],
            'properties' => [
                'reason' => [
                    'type' => 'string',
                    // "not sure" and "out of scope" are not the owner's reasons to
                    // stop the bot - the model answers or asks instead.
                    'enum' => ['call_request', 'customer_request', 'complaint', 'document_unresolvable', 'other'],
                    'description' => 'call_request = he asks for a phone/voice call: staff call him and you KEEP answering him here meanwhile. customer_request = he asks for a person instead of you. other = he insists on a discount, IDENTITY_IN_USE_BY_ANOTHER_CUSTOMER, or he insists on information we do not have to decide.',
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
