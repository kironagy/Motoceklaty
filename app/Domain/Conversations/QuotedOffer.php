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

    private const LEDGER_KEY = 'quotes';

    private const LEDGER_SIZE = 6;

    /** A quote older than this is not repeated without a new lookup (Q-17 proposal: 24 h). */
    private const VALID_HOURS = 24;

    /**
     * Rebuild (price memory between turns): every offer a tool really gave
     * him, with its numbers and the motorcycle's price version, so a later
     * "القسط كام تاني؟" is answered from it instead of a new lookup.
     */
    public static function addToLedger(int $conversationId, int $machineId, array $offer): void
    {
        $conversation = WhatsappConversation::find($conversationId);
        $machine = \App\Models\Machine::find($machineId, ['id', 'name', 'cash_price', 'updated_at']);

        if (! $conversation || ! $machine || ! isset($offer['months'], $offer['monthly_payment'])) {
            return;
        }

        $entry = [
            'motorcycle_id' => $machine->id,
            'motorcycle' => (string) $machine->name,
            'cash_price' => (int) round((float) $machine->cash_price),
            'months' => (int) $offer['months'],
            'monthly_payment' => (int) round((float) $offer['monthly_payment']),
            'cash_due_upfront' => (int) round((float) ($offer['cash_due_upfront'] ?? 0)),
            'admin_fee_at_pickup' => (int) round((float) ($offer['admin_fee_at_pickup'] ?? 0)),
            'total_paid' => (int) round((float) ($offer['total_paid'] ?? 0)),
            'price_version' => $machine->updated_at?->toIso8601String(),
            'at' => now()->toIso8601String(),
        ];

        $ledger = array_values(array_filter((array) (($conversation->state ?? [])[self::LEDGER_KEY] ?? []),
            fn ($q) => ! ((int) ($q['motorcycle_id'] ?? 0) === $entry['motorcycle_id'] && (int) ($q['months'] ?? 0) === $entry['months'])));
        array_unshift($ledger, $entry);

        $conversation->update(['state' => array_merge($conversation->state ?? [], [self::LEDGER_KEY => array_slice($ledger, 0, self::LEDGER_SIZE)])]);
    }

    private const PRICES_KEY = 'cash_prices_shown';

    /** The cash prices a tool showed him (id + price version), for the same 24 h as the quotes. */
    public static function addCashPrices(int $conversationId, array $machineIds): void
    {
        $conversation = WhatsappConversation::find($conversationId);

        if (! $conversation || $machineIds === []) {
            return;
        }

        $shown = (array) (($conversation->state ?? [])[self::PRICES_KEY] ?? []);

        foreach (\App\Models\Machine::whereIn('id', $machineIds)->get(['id', 'updated_at']) as $machine) {
            $shown[(string) $machine->id] = ['price_version' => $machine->updated_at?->toIso8601String(), 'at' => now()->toIso8601String()];
        }

        $conversation->update(['state' => array_merge($conversation->state ?? [], [self::PRICES_KEY => array_slice($shown, -12, null, true)])]);
    }

    /**
     * Cash prices shown in the last 24 h whose price is unchanged - read from
     * the catalog now, so it is the real price, not a remembered one.
     *
     * @return list<array{motorcycle_id: int, motorcycle: string, cash_price: int}>
     */
    public static function cashPricesShown(WhatsappConversation $conversation): array
    {
        $shown = (array) (($conversation->state ?? [])[self::PRICES_KEY] ?? []);

        if ($shown === []) {
            return [];
        }

        return \App\Models\Machine::whereIn('id', array_keys($shown))->get(['id', 'name', 'cash_price', 'updated_at'])
            ->filter(fn ($m) => \Illuminate\Support\Carbon::parse($shown[(string) $m->id]['at'] ?? '2000-01-01')->gt(now()->subHours(self::VALID_HOURS))
                && $m->updated_at?->toIso8601String() === ($shown[(string) $m->id]['price_version'] ?? null))
            ->map(fn ($m) => ['motorcycle_id' => $m->id, 'motorcycle' => trim((string) $m->name), 'cash_price' => (int) round((float) $m->cash_price)])
            ->values()->all();
    }

    /**
     * The quotes still true: given within the validity window and the
     * motorcycle's price not edited since.
     *
     * @return list<array<string, mixed>>
     */
    public static function ledger(WhatsappConversation $conversation): array
    {
        $quotes = (array) (($conversation->state ?? [])[self::LEDGER_KEY] ?? []);
        $versions = \App\Models\Machine::whereIn('id', array_column($quotes, 'motorcycle_id'))->pluck('updated_at', 'id');

        return array_values(array_map(
            fn ($q) => array_diff_key($q, ['price_version' => 1, 'motorcycle_id' => 1]),
            array_filter($quotes, fn ($q) => isset($q['at'])
                && \Illuminate\Support\Carbon::parse($q['at'])->gt(now()->subHours(self::VALID_HOURS))
                && ($versions[$q['motorcycle_id'] ?? 0] ?? null)?->toIso8601String() === ($q['price_version'] ?? null)),
        ));
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
