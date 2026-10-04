<?php

namespace App\Domain\Conversations;

use App\Models\WhatsappConversation;

/**
 * The last single offer he was quoted: motorcycle, duration, plan, down
 * payment. QA 2026-10-04: he chose "سنة ونص" (مقدم 3,000، قسط 4,533), the
 * application opened with no plan, and he was asked "حابب تقسطها على قد
 * إيه؟" again before submitting. Structured state - no model memory.
 */
class QuotedOffer
{
    private const KEY = 'last_quoted_offer';

    /** @param array{machine_id: int, months: int, plan_id: int, down_payment: float} $offer */
    public static function remember(int $conversationId, array $offer): void
    {
        $conversation = WhatsappConversation::find($conversationId);

        if ($conversation) {
            $conversation->update(['state' => array_merge($conversation->state ?? [], [self::KEY => $offer + ['at' => now()->toIso8601String()]])]);
        }
    }

    /** @return array{machine_id: int, months: int, plan_id: int, down_payment: float}|null */
    public static function last(int $conversationId, ?int $machineId = null): ?array
    {
        $offer = WhatsappConversation::find($conversationId)?->state[self::KEY] ?? null;

        if (! is_array($offer) || ($machineId !== null && (int) ($offer['machine_id'] ?? 0) !== $machineId)) {
            return null;
        }

        return $offer;
    }
}
