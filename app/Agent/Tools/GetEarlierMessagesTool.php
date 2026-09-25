<?php

namespace App\Agent\Tools;

use App\Agent\Tracing\Redactor;
use App\Models\WhatsappMessage;

/** READ — plan §6.17 */
class GetEarlierMessagesTool implements Tool
{
    public function name(): string
    {
        return 'get_earlier_messages';
    }

    public function description(): string
    {
        return 'Read older messages beyond the recent window/summary when a customer reference needs them. '
            .'Use when the customer refers to something not in the visible recent context. '
            .'Do not use for messages already in the recent window.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'before_message_id' => ['type' => 'integer'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
            ],
        ];
    }

    public function permission(): string
    {
        return 'READ';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $limit = $args['limit'] ?? 10;

        $messages = WhatsappMessage::query()
            ->where('whatsapp_conversation_id', $ctx->conversationId)
            ->when(isset($args['before_message_id']), fn ($q) => $q->where('id', '<', $args['before_message_id']))
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'direction', 'sender_type', 'type', 'text', 'transcript', 'created_at'])
            ->sortBy('id')
            ->values()
            ->map(fn (WhatsappMessage $m) => Redactor::redact([
                'id' => $m->id,
                'direction' => $m->direction,
                'sender_type' => $m->sender_type,
                'type' => $m->type,
                'text' => $m->text ?? $m->transcript,
                'created_at' => $m->created_at?->toIso8601String(),
            ]))
            ->all();

        return ToolResult::ok(['messages' => $messages]);
    }
}
