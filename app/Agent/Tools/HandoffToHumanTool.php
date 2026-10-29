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
        return config('agent.handoff.tool_description') ?: 'Give the conversation to staff (the bot then stops, except call_request). Only when: he asks for a person or a call, '
            .'a complaint about a bike or money, he insists on a discount, IDENTITY_IN_USE_BY_ANOTHER_CUSTOMER, or he insists on information we do not have.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => config('agent.handoff.customer_only') ? ['reason', 'note', 'request_quote'] : ['reason', 'note'],
            'properties' => [
                'reason' => [
                    'type' => 'string',
                    // "not sure" and "out of scope" are not the owner's reasons to
                    // stop the bot - the model answers or asks instead.
                    'enum' => config('agent.handoff.customer_only') ? ['call_request', 'customer_request'] : ['call_request', 'customer_request', 'complaint', 'document_unresolvable', 'other'],
                    'description' => config('agent.handoff.tool_description') ?: 'call_request = he asks for a phone/voice call: staff call him and you KEEP answering him here meanwhile. customer_request = he asks for a person instead of you. other = he insists on a discount, IDENTITY_IN_USE_BY_ANOTHER_CUSTOMER, or he insists on information we do not have to decide.',
                ],
                'request_quote' => ['type' => 'string', 'description' => 'Exact quote from his current turn.'],
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

        if (config('agent.handoff.customer_only')) {
            if (! in_array($args['reason'] ?? '', ['customer_request', 'call_request'], true)) {
                return ToolResult::error('CUSTOMER_REQUEST_REQUIRED');
            }
            $message = \App\Models\WhatsappMessage::where('whatsapp_conversation_id', $ctx->conversationId)
                ->where('turn_id', $ctx->turnId)->where('sender_type', 'customer')->orderBy('id')->get(['text', 'transcript'])->map(fn ($row) => $row->text ?: $row->transcript)->implode("\n");
            if (! app(\App\Agent\Runtime\EvidenceReview::class)->customerRequestedHandoff(trim((string) ($args['request_quote'] ?? '')), $message)) {
                return ToolResult::error('CUSTOMER_REQUEST_REQUIRED');
            }
        }

        $this->handoffService->handOff($conversation, $args['reason'], $args['note'], source: 'ai');

        return ToolResult::ok(['handed_off' => true]);
    }
}
