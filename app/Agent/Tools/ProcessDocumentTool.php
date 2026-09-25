<?php

namespace App\Agent\Tools;

use App\Domain\Applications\SnapshotService;
use App\Domain\Documents\DocumentPipeline;
use App\Models\Application;
use App\Models\MessageMedia;

/** WRITE — plan §6.12 */
class ProcessDocumentTool implements Tool
{
    public function __construct(
        private readonly DocumentPipeline $pipeline,
        private readonly SnapshotService $snapshots,
    ) {
    }

    public function name(): string
    {
        return 'process_document';
    }

    public function description(): string
    {
        return 'Run the full document pipeline on media the customer sent. Use when the customer sends an image or PDF '
            .'that looks like a document - pass every media_id shown as "[صورة مرفقة - media_id: N]" in one call. '
            .'Images from earlier messages that were never processed are listed in the state as unprocessed_media - '
            .'process those instead of asking the customer to send them again. Set replaces_previous=true only when '
            .'the customer says a document they sent before was wrong / not theirs and this one replaces it. '
            .'Do not use for motorcycle photos.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['media_ids'],
            'properties' => [
                'media_ids' => [
                    'type' => 'array', 'minItems' => 1, 'maxItems' => 4,
                    'items' => ['type' => 'integer'],
                ],
                'expected_document_type' => ['type' => 'string', 'description' => 'A document type key from the snapshot (documents.required / missing), or omit.'],
                'replaces_previous' => ['type' => 'boolean', 'description' => 'true only when the customer said an earlier document of this kind was wrong and this one replaces it.'],
            ],
        ];
    }

    public function permission(): string
    {
        return 'WRITE';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $application = $ctx->activeApplicationId ? Application::find($ctx->activeApplicationId) : null;

        if (! $application) {
            return ToolResult::error('NO_ACTIVE_APPLICATION');
        }

        $expected = $args['expected_document_type'] ?? null;

        // "national_id" (not a real key) was accepted and stored as the
        // expected type; a wrong key is an error the model can fix.
        if ($expected !== null && ! \App\Models\DocumentType::where('key', $expected)->where('is_active', true)->exists()) {
            return ToolResult::error('UNKNOWN_DOCUMENT_TYPE', 'expected_document_type must be one of: '
                .\App\Models\DocumentType::where('is_active', true)->pluck('key')->implode(', ').' - or omit it.');
        }

        // A text-only "كده تمام؟" led the model to invent media_id 1, get
        // MEDIA_NOT_FOUND, and tell the customer their photo never arrived.
        $known = MessageMedia::whereIn('id', $args['media_ids'])
            ->whereHas('message', fn ($q) => $q->where('whatsapp_conversation_id', $ctx->conversationId)->where('direction', 'incoming'))
            ->exists();

        if (! $known) {
            return ToolResult::error('NO_SUCH_MEDIA', 'None of these media_ids exist in this conversation. If this message has no '
                .'attachment, answer from the snapshot (documents.accepted / partial) - do not tell the customer an image failed.');
        }

        $results = [];

        foreach ($args['media_ids'] as $mediaId) {
            $media = MessageMedia::with('message')->find($mediaId);

            if (! $media || $media->message?->whatsapp_conversation_id !== $ctx->conversationId || $media->message?->direction !== 'incoming') {
                $results[] = ['media_id' => $mediaId, 'document_id' => null, 'detected_type' => null, 'accepted' => false, 'status' => 'failed', 'issues' => [['code' => 'MEDIA_NOT_FOUND']], 'applied_fields' => []];

                continue;
            }

            $results[] = $this->pipeline->process($media, $application, $expected, ($args['replaces_previous'] ?? false) === true);
        }

        return ToolResult::ok([
            'results' => $results,
            'snapshot' => $this->snapshots->for($application->refresh()),
        ]);
    }
}
