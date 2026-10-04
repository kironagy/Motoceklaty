<?php

namespace App\Domain\Conversations;

use App\Models\Customer;
use App\Models\WhatsappConversation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Which WhatsApp numbers are live right now, and moving a chat off a number
 * that is not. 2026-10-04: staff decisions on 100 chats of bot 91 (logged
 * out) failed with "session not found" - the customer never heard of them.
 */
class BotSessions
{
    /** @return int[]|null bot ids the WhatsApp worker reports as connected, null when it can't say */
    public function connected(): ?array
    {
        try {
            $response = Http::connectTimeout(5)->timeout(10)
                ->withHeaders(['X-BOT-TOKEN' => config('services.whatsapp.bot_token'), 'Accept' => 'application/json'])
                ->get(config('services.whatsapp.worker_url').'/status');
        } catch (\Throwable $e) {
            Log::warning('BotSessions: worker status unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        if (! is_array($response->json('sessions'))) {
            return null;
        }

        return collect($response->json('sessions'))
            ->where('connected', true)
            ->pluck('bot_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * The conversation to send on: itself while its number is connected,
     * otherwise moved (with its customer) to a connected number so his reply
     * lands back in the same chat with his application. When he already has
     * a chat on that number, that chat is used instead. Null when no number
     * is connected at all.
     */
    public function liveConversation(WhatsappConversation $conversation): ?WhatsappConversation
    {
        $connected = $this->connected();

        // Worker status unknown: send as before and let delivery report it.
        if ($connected === null) {
            return $conversation;
        }

        if (in_array((int) $conversation->whatsapp_bot_id, $connected, true)) {
            return $conversation;
        }

        $botId = $connected[0] ?? null;

        if (! $botId) {
            return null;
        }

        $existing = WhatsappConversation::where('whatsapp_bot_id', $botId)->where('phone', $conversation->phone)->first();

        if ($existing) {
            return $existing;
        }

        $fromBot = $conversation->whatsapp_bot_id;

        DB::transaction(function () use ($conversation, $botId) {
            $customer = $conversation->customer;

            if ($customer && ! Customer::where('whatsapp_bot_id', $botId)->where('jid', $customer->jid)->exists()) {
                $customer->update(['whatsapp_bot_id' => $botId]);
            }

            $conversation->update(['whatsapp_bot_id' => $botId]);
        });

        Log::info('BotSessions: conversation moved to a connected number', [
            'conversation_id' => $conversation->id, 'from_bot' => $fromBot, 'to_bot' => $botId,
        ]);

        return $conversation->refresh();
    }
}
