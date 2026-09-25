<?php

namespace App\Domain\Conversations;

use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Delivers a turn's generated result at most once per item. Every item
 * (text or media) gets its own outbound whatsapp_messages row created
 * *before* the send attempt, so a retry after a partial failure only
 * resends the items that never reached 'sent' (plan T05 §5) - fixing the
 * old full-resend-on-retry duplication.
 */
class DeliveryService
{
    /** Set by dryRun(): everything is persisted as usual but nothing reaches the WhatsApp worker. */
    private bool $dryRun = false;

    /**
     * A copy that records the reply exactly like a real delivery (same rows,
     * same formatting, same media memory) without calling the WhatsApp
     * worker. Used by the conversation simulator.
     */
    public function dryRun(): static
    {
        $copy = clone $this;
        $copy->dryRun = true;

        return $copy;
    }

    private function postToWorker(string $endpoint, array $payload, int $timeout): \Illuminate\Http\Client\Response
    {
        if ($this->dryRun) {
            return new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                'ok' => true,
                'wa_message_id' => 'sim-'.\Illuminate\Support\Str::random(16),
            ])));
        }

        return Http::connectTimeout(10)->timeout($timeout)
            ->withHeaders(['X-BOT-TOKEN' => config('services.whatsapp.bot_token'), 'Accept' => 'application/json'])
            ->post(config('services.whatsapp.worker_url').$endpoint, $payload);
    }

    /**
     * @param  array{messages?: string[], quote_wa_message_id?: ?string, media?: array, focus_motorcycle_ids?: int[]}  $result
     */
    public function deliver(object $turn, array $result, string $senderType = 'bot', bool $requireAgentEnabled = true): void
    {
        if ($requireAgentEnabled && ! config('agent.enabled')) {
            throw new \RuntimeException('DeliveryService: refusing to deliver while agent.enabled=false.');
        }

        $messages = $result['messages'] ?? [];
        $quoteWaMessageId = $result['quote_wa_message_id'] ?? null;
        $mediaItems = $result['media'] ?? [];
        $focusMotorcycleIds = $result['focus_motorcycle_ids'] ?? [];

        foreach ($messages as $index => $text) {
            if ($this->superseded($turn)) {
                return;
            }

            $this->deliverText($turn, $index, (string) $text, $quoteWaMessageId, $senderType, $focusMotorcycleIds);
        }

        foreach ($mediaItems as $index => $item) {
            if ($this->superseded($turn)) {
                return;
            }

            $this->deliverMedia($turn, $index, $item, $senderType);
        }
    }

    /**
     * Re-read before every item: a customer message that lands mid-delivery
     * supersedes the rest of a now-stale reply (DEC-07).
     */
    private function superseded(object $turn): bool
    {
        return DB::table('whatsapp_message_jobs')
            ->where('id', $turn->id)
            ->where(fn ($q) => $q->where('status', 'superseded')->orWhereNotNull('superseded_by'))
            ->exists();
    }

    /**
     * Staff dashboard reply (T07): not a scheduled turn, but every send is
     * still recorded as its own real turn row for a full audit trail and to
     * reuse the exact same per-item outbound/wa_message_id tracking.
     *
     * $requireAgentEnabled defaults true because this path is part of the
     * new agent-adjacent staff workflow (T07) and should honor the master
     * switch like the rest of it. T14's legacy status notification
     * (SendWhatsappStatusNotification) predates the agent entirely and
     * must keep sending regardless of agent.enabled - it passes false to
     * preserve that pre-existing behavior while still getting a persisted
     * outbound row for its audit trail.
     */
    public function deliverForConversation(WhatsappConversation $conversation, array $result, string $senderType = 'agent', ?string $jidOverride = null, bool $requireAgentEnabled = true): void
    {
        $jid = $jidOverride ?? $conversation->customer?->jid ?? $conversation->phone;

        $turnId = DB::table('whatsapp_message_jobs')->insertGetId([
            'whatsapp_bot_id' => $conversation->whatsapp_bot_id,
            'whatsapp_conversation_id' => $conversation->id,
            'from' => $jid,
            'reply_jid' => $jid,
            'message' => $result['messages'][0] ?? '[media]',
            'status' => 'done',
            'attempts' => 0,
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $turn = DB::table('whatsapp_message_jobs')->find($turnId);

        $this->deliver($turn, $result, $senderType, $requireAgentEnabled);
    }

    private function deliverText(object $turn, int $index, string $text, ?string $quoteWaMessageId, string $senderType, array $focusMotorcycleIds = []): void
    {
        $text = \App\Support\WhatsAppText::format($text);

        if (trim($text) === '' || $this->alreadySent($turn->id, 'text', $index)) {
            return;
        }

        $outbound = $this->outboundRow($turn, 'text', $index, $text, $senderType);

        if ($focusMotorcycleIds !== []) {
            $outbound->update(['metadata' => array_merge($outbound->metadata ?? [], ['focus_motorcycle_ids' => $focusMotorcycleIds])]);
        }

        // The worker paces sends per number (anti-ban), so a busy minute
        // can hold a send well past a minute. Giving up earlier made the
        // retry resend a message the worker was still about to deliver.
        $response = $this->postToWorker('/send-message', [
            'bot_id' => (string) $turn->whatsapp_bot_id,
            'jid' => $turn->reply_jid ?: $turn->from,
            'message' => $text,
            'quoted_message' => $quoteWaMessageId,
        ], 180);

        $this->finalizeDelivery($outbound, $response);
    }

    private function deliverMedia(object $turn, int $index, array $item, string $senderType): void
    {
        if ($this->alreadySent($turn->id, 'media', $index)) {
            return;
        }

        $outbound = $this->outboundRow($turn, 'media', $index, $item['caption'] ?? '[media]', $senderType);

        // Which catalog photo this was: a customer replying to it ("والأبيض
        // ده مش متاح؟") is asking about exactly this model and color.
        if (! empty($item['motorcycle_id'])) {
            $outbound->update(['metadata' => array_merge($outbound->metadata ?? [], array_filter([
                'motorcycle_id' => (int) $item['motorcycle_id'],
                'image_color' => $item['image_color'] ?? null,
            ]))]);
        }

        // Legacy dashboard rendering (chat.blade.php) reads
        // payload.saved_media_items - keep it populated until T20.
        $outbound->update(['payload' => ['saved_media_items' => [[
            'type' => $item['type'] ?? 'image',
            'mime' => $item['mime'] ?? null,
            'filename' => $item['filename'] ?? null,
            'path' => $item['path'] ?? null,
            'url' => $item['url'] ?? null,
        ]]]]);

        $response = $this->postToWorker('/send-media-items', [
            'bot_id' => (string) $turn->whatsapp_bot_id,
            'jid' => $turn->reply_jid ?: $turn->from,
            'media_items' => [$item],
        ], 240);

        $this->finalizeDelivery($outbound, $response);
        $this->rememberMediaSent($turn, $item);
    }

    /**
     * Recorded when the photo was actually delivered, not when the tool
     * queued it: photos queued in a turn that ended in a fallback were never
     * sent, yet a resend was refused for half an hour.
     */
    private function rememberMediaSent(object $turn, array $item): void
    {
        if (empty($item['motorcycle_id']) || ! $turn->whatsapp_conversation_id) {
            return;
        }

        $conversation = WhatsappConversation::find($turn->whatsapp_conversation_id);

        if (! $conversation) {
            return;
        }

        $state = $conversation->state ?? [];
        $entries = array_values(array_filter(
            $state['last_media_sent'] ?? [],
            fn ($e) => ! (($e['motorcycle_id'] ?? null) === $item['motorcycle_id'] && ($e['color'] ?? null) === ($item['color'] ?? null))
        ));
        $entries[] = ['motorcycle_id' => $item['motorcycle_id'], 'color' => $item['color'] ?? null, 'sent_at' => now()->toIso8601String()];
        $state['last_media_sent'] = $entries;
        $conversation->update(['state' => $state]);
    }

    private function alreadySent(int $turnId, string $kind, int $index): bool
    {
        return WhatsappMessage::where('turn_id', $turnId)
            ->where('delivery_status', 'sent')
            ->where('metadata->delivery_kind', $kind)
            ->where('metadata->delivery_index', $index)
            ->exists();
    }

    private function outboundRow(object $turn, string $kind, int $index, string $text, string $senderType): WhatsappMessage
    {
        // A retry reuses the row of the attempt that failed: three attempts
        // used to leave three 'failed' copies of the same reply.
        $previous = WhatsappMessage::where('turn_id', $turn->id)
            ->where('direction', 'outgoing')
            ->whereIn('delivery_status', ['queued', 'failed'])
            ->where('metadata->delivery_kind', $kind)
            ->where('metadata->delivery_index', $index)
            ->first();

        if ($previous) {
            $previous->update(['delivery_status' => 'queued', 'text' => $kind === 'text' ? $text : null, 'message' => $text]);

            return $previous;
        }

        return WhatsappMessage::create([
            'whatsapp_conversation_id' => $turn->whatsapp_conversation_id,
            'whatsapp_bot_id' => $turn->whatsapp_bot_id,
            'turn_id' => $turn->id,
            'direction' => 'outgoing',
            'sender_type' => $senderType,
            'type' => $kind === 'text' ? 'text' : 'image',
            'text' => $kind === 'text' ? $text : null,
            'message' => $text,
            'delivery_status' => 'queued',
            'metadata' => ['delivery_kind' => $kind, 'delivery_index' => $index],
        ]);
    }

    private function finalizeDelivery(WhatsappMessage $outbound, \Illuminate\Http\Client\Response $response): void
    {
        $ok = $response->successful() && $response->json('ok');

        if (! $ok) {
            $outbound->update(['delivery_status' => 'failed']);

            Log::warning('Turn delivery item failed', [
                'outbound_message_id' => $outbound->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('Delivery failed: '.$response->status().' - '.$response->body());
        }

        $waMessageIds = $response->json('wa_message_ids') ?? $response->json('wa_message_id');
        $waMessageId = is_array($waMessageIds) ? ($waMessageIds[0] ?? null) : $waMessageIds;

        $outbound->update([
            'delivery_status' => 'sent',
            'wa_message_id' => $waMessageId,
        ]);
    }
}
