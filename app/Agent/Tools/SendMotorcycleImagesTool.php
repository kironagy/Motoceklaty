<?php

namespace App\Agent\Tools;

use App\Domain\Catalog\CatalogService;
use App\Models\WhatsappConversation;
use Illuminate\Support\Facades\Storage;

/** WRITE (outbound queue) — plan §6.3 */
class SendMotorcycleImagesTool implements Tool
{
    public function __construct(private readonly CatalogService $catalog)
    {
    }

    public function name(): string
    {
        return 'send_motorcycle_images';
    }

    public function description(): string
    {
        return 'Queue real catalog images of one motorcycle. They are delivered BEFORE the reply text, '
            .'so write the text as a follow-up to photos the customer is already looking at. '
            .'Use when the customer asks for photos, or showing photos clearly helps. Do not use when the '
            .'same images were sent recently.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['motorcycle_id'],
            'properties' => [
                'motorcycle_id' => ['type' => 'integer'],
                'color' => ['type' => 'string'],
                'max' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4],
                'resend' => ['type' => 'boolean', 'description' => 'true ONLY when the customer explicitly asked to get the same photos again (e.g. they did not open). Skips the recently-sent check.'],
            ],
        ];
    }

    public function permission(): string
    {
        return 'WRITE';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        if (! $this->catalog->machineExists($args['motorcycle_id'])) {
            return ToolResult::error('UNKNOWN_MOTORCYCLE');
        }

        $max = $args['max'] ?? 4;
        $color = $args['color'] ?? null;
        $images = $this->catalog->images($args['motorcycle_id'], $color, $max);

        if ($color !== null && $images['paths'] === []) {
            return ToolResult::error('UNKNOWN_COLOR', "No images in color {$color}. Colors with images: "
                .implode('، ', $images['colors_available']).' - offer these, or call again without color.');
        }

        if ($images['paths'] === []) {
            return ToolResult::error('NO_IMAGES');
        }

        // Same model's photos twice in one reply (with and without a color).
        if ($ctx->outbound->hasMediaFor((int) $args['motorcycle_id'])) {
            return ToolResult::error('ALREADY_QUEUED_THIS_TURN', 'Photos of this motorcycle are already attached to this reply.');
        }

        $conversation = WhatsappConversation::findOrFail($ctx->conversationId);
        $state = $conversation->state ?? [];
        $lastSent = $state['last_media_sent'] ?? [];

        $resendWindow = config('agent.images.resend_window_minutes');

        if ($resendWindow !== null && ($args['resend'] ?? false) !== true) {
            $cutoff = now()->subMinutes((int) $resendWindow);

            foreach ($lastSent as $entry) {
                if (($entry['motorcycle_id'] ?? null) === $args['motorcycle_id']
                    && ($entry['color'] ?? null) === $color
                    && ! empty($entry['sent_at'])
                    && \Illuminate\Support\Carbon::parse($entry['sent_at'])->gt($cutoff)) {
                    return ToolResult::error('ALREADY_SENT_RECENTLY');
                }
            }
        }

        // last_media_sent is written by DeliveryService once a photo really
        // went out - queuing is not sending (the turn may still be dropped).
        foreach ($images['paths'] as $i => $path) {
            $ctx->outbound->addMedia([
                'type' => 'image',
                'url' => Storage::disk('public')->url($path),
                'path' => $path,
                'caption' => '',
                'motorcycle_id' => $args['motorcycle_id'],
                'color' => $color,
                'image_color' => $images['colors'][$i] ?? null,
            ]);
        }

        \App\Domain\Conversations\QuotedMotorcycle::remember($ctx->conversationId, (int) $args['motorcycle_id']);

        return ToolResult::ok([
            'queued_count' => count($images['paths']),
            // what the customer is about to see, photo by photo - talk about these colors,
            // e.g. two photos رمادي + أبيض means both colors are available
            'sent_photo_colors' => array_values(array_filter($images['colors'])),
            'colors_available' => $images['colors_available'],
        ]);
    }
}
