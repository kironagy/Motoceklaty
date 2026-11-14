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

        // Owner 2026-11-14: WHATSAPP_GATEWAY=automoon sends a bot that has an Automoon device through
        // Automoon; the other bots (and every bot when it is 'worker') keep the worker path below.
        if (\App\Services\AutomoonGateway::forBot($payload['bot_id'] ?? null)) {
            return app(\App\Services\AutomoonGateway::class)->send($endpoint, $payload, min($timeout, 120));
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

        // Owner 2026-11-10 (anti-ban): a reply split into 2-3 WhatsApp messages doubled what each
        // number sent. The texts of one reply go out as ONE message, paragraphs kept.
        $texts = array_values(array_filter(array_map(fn ($m) => trim((string) $m), $messages), fn ($m) => $m !== ''));
        if (count($texts) > 1) {
            $messages = [implode("\n\n", $texts)];
        }

        $quoteWaMessageId = $result['quote_wa_message_id'] ?? null;
        $mediaItems = $result['media'] ?? [];
        $focusMotorcycleIds = $result['focus_motorcycle_ids'] ?? [];

        // Photos go first: the text usually talks about them ("دي صور الأحمر، إيه رأيك؟").
        foreach ($mediaItems as $index => $item) {
            if ($this->superseded($turn)) {
                return;
            }

            $this->deliverMedia($turn, $index, $item, $senderType);
        }

        foreach ($messages as $index => $text) {
            if ($this->superseded($turn)) {
                return;
            }

            $this->deliverText($turn, $index, (string) $text, $quoteWaMessageId, $senderType, $focusMotorcycleIds);
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
    public function deliverForConversation(WhatsappConversation $conversation, array $result, string $senderType = 'agent', ?string $jidOverride = null, bool $requireAgentEnabled = true, ?string $dedupeKey = null): void
    {
        $jid = $jidOverride ?? $conversation->customer?->jid ?? $conversation->phone;

        // Conversation 375: a reminder whose send failed or timed out (the worker
        // may still deliver it) went out again as a NEW turn on every run - 7 times.
        // The same key reuses the same turn and outbound row, so the worker's
        // client_id registry sends it at most once.
        $existing = $dedupeKey === null ? null
            : DB::table('whatsapp_message_jobs')->where('dedupe_key', $dedupeKey)->first();

        if ($existing) {
            $this->deliver($existing, $result, $senderType, $requireAgentEnabled);

            return;
        }

        try {
            $turnId = DB::table('whatsapp_message_jobs')->insertGetId([
                'whatsapp_bot_id' => $conversation->whatsapp_bot_id,
                'whatsapp_conversation_id' => $conversation->id,
                'from' => $jid,
                'reply_jid' => $jid,
                'message' => $result['messages'][0] ?? '[media]',
                'status' => 'done',
                'attempts' => 0,
                'dedupe_key' => $dedupeKey,
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // a parallel run claimed the key first: it sends, this one does not
            return;
        }

        $turn = DB::table('whatsapp_message_jobs')->find($turnId);

        $this->deliver($turn, $result, $senderType, $requireAgentEnabled);
    }

    private function deliverText(object $turn, int $index, string $text, ?string $quoteWaMessageId, string $senderType, array $focusMotorcycleIds = []): void
    {
        $text = \App\Support\WhatsAppText::format($text);

        if (trim($text) === '' || $this->alreadySent($turn->id, 'text', $index)) {
            return;
        }

        if ($senderType !== 'agent' && $this->repeatsSinceCustomer($turn, $text)) {
            Log::info('Outbound repeat dropped', ['turn_id' => $turn->id, 'sender_type' => $senderType]);

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
            // ERR-003: a resend of an item the worker already sent is not sent again
            'client_id' => 'out_'.$outbound->id,
            // 2026-11-12: the worker also holds a too-fast reply up to 90 s (human pacing).
        ], 540); // 2026-11-13: one send per number every 1-2 min; under the 10-min stale-lock reclaim

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
            'client_id' => 'out_'.$outbound->id,
        ], 540);

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

    /**
     * The last safety net against repeats (conversations 231, 372, 375): the same
     * automated or bot text, already sent (or possibly sent) since his last message,
     * is not sent again - he has not said anything it could be answering.
     * A colleague's own words ('agent') are never blocked.
     */
    private function repeatsSinceCustomer(object $turn, string $text): bool
    {
        $key = self::sameText($text);

        if ($key === '') {
            return false;
        }

        $lastIncoming = WhatsappMessage::where('whatsapp_conversation_id', $turn->whatsapp_conversation_id)
            ->where('direction', 'incoming')->max('id');

        return WhatsappMessage::where('whatsapp_conversation_id', $turn->whatsapp_conversation_id)
            ->where('direction', 'outgoing')
            ->where('turn_id', '!=', $turn->id)
            ->whereIn('delivery_status', ['sent', 'queued'])
            ->when($lastIncoming, fn ($q) => $q->where('id', '>', $lastIncoming))
            ->whereNotNull('text')
            ->pluck('text')
            ->contains(fn ($sent) => self::sameText((string) $sent) === $key);
    }

    /** Text compared without punctuation, spacing, emoji or Arabic letter variants. */
    public static function sameText(string $text): string
    {
        $text = \App\Support\ArabicTextNormalizer::normalize($text);

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $text);
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
