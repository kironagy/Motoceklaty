<?php

namespace App\Domain\Conversations;

use App\Models\Machine;
use App\Models\WhatsappConversation;

/**
 * The motorcycle the customer was last given a price, an installment or
 * photos for. Live: the bot quoted "هوجن ٤ استيراد" (15) and a turn later
 * opened the application on "هوجن ٤ استيراد فرز تاني" (7) - a different
 * price, and the customer would never have noticed.
 */
class QuotedMotorcycle
{
    public static function remember(int $conversationId, int $motorcycleId): void
    {
        $conversation = WhatsappConversation::find($conversationId);

        if (! $conversation) {
            return;
        }

        $state = $conversation->state ?? [];

        if (($state['last_quoted_motorcycle_id'] ?? null) !== $motorcycleId) {
            $state['last_quoted_motorcycle_id'] = $motorcycleId;
            $conversation->update(['state' => $state]);
        }
    }

    public static function last(int $conversationId): ?int
    {
        $id = WhatsappConversation::find($conversationId)?->state['last_quoted_motorcycle_id'] ?? null;

        return $id !== null ? (int) $id : null;
    }

    /** An error detail when $motorcycleId is not the one just quoted and the switch was not confirmed, else null. */
    public static function mismatch(int $conversationId, int $motorcycleId, bool $confirmed): ?string
    {
        $last = self::last($conversationId);

        if ($confirmed || $last === null || $last === $motorcycleId) {
            return null;
        }

        $names = Machine::whereIn('id', [$last, $motorcycleId])->pluck('name', 'id');

        return "The customer was last quoted \"{$names[$last]}\" (id {$last}), not \"{$names[$motorcycleId]}\" (id {$motorcycleId}). "
            ."Use {$last} - or, only if he really chose the other model, call again with different_motorcycle_confirmed: true.";
    }
}
