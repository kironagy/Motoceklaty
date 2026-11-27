<?php

namespace App\Domain\Conversations;

use App\Models\Customer;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;

/**
 * Owner 2026-11-27: the system writing first to a phone (a letter is ready, the owner's own alerts):
 * his chat on a connected number when he has one, otherwise a new chat on a connected number.
 */
class DirectWhatsapp
{
    /** 01012345678 / +20 101 234 5678 → 201012345678, or null when it is not an Egyptian mobile */
    public static function phone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        $digits = str_starts_with($digits, '0') ? '2'.$digits : $digits;

        return preg_match('/^201\d{9}$/', $digits) ? $digits : null;
    }

    public static function conversation(string $phone, ?string $status = null): ?WhatsappConversation
    {
        $live = WhatsappBot::query()->where('is_active', true)->whereNull('archived_at')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $connected = app(BotSessions::class)->connected();
        $bots = $connected === null ? $live : array_values(array_intersect($connected, $live));

        if ($bots === []) {
            return null;
        }

        $conversation = WhatsappConversation::where('phone', $phone)->whereIn('whatsapp_bot_id', $bots)->latest('id')->first();

        if (! $conversation) {
            $customer = Customer::firstOrCreate(['whatsapp_bot_id' => $bots[0], 'jid' => $phone.'@s.whatsapp.net'], ['phone' => $phone]);
            $conversation = WhatsappConversation::firstOrCreate(
                ['whatsapp_bot_id' => $bots[0], 'phone' => $phone],
                ['status' => 'open', 'customer_id' => $customer->id],
            );
        }

        if ($status !== null && $conversation->status !== $status) {
            $conversation->update(['status' => $status]);
        }

        return $conversation;
    }
}
