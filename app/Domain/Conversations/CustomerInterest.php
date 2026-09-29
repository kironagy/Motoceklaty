<?php

namespace App\Domain\Conversations;

use App\Domain\Catalog\CatalogService;
use App\Models\Machine;
use App\Models\WhatsappConversation;

/**
 * What kind and size of motorcycle the customer is after - from a model he
 * named or a photo he sent, carried or not. A later "ايه الموجود؟" is
 * answered from it (simulators 690/691: an SRK 250 photo, then a 150cc
 * Dayun and a Boxer as "what we have").
 */
class CustomerInterest
{
    public static function remember(int $conversationId, string $label, ?int $cc, ?string $kind = 'motorcycle', ?float $price = null, ?int $motorcycleId = null): void
    {
        $conversation = WhatsappConversation::find($conversationId);

        if (! $conversation || $cc === null) {
            return;
        }

        $state = $conversation->state ?? [];
        $state['interest'] = array_filter(['label' => $label, 'cc' => $cc, 'kind' => $kind ?? 'motorcycle', 'price' => $price, 'id' => $motorcycleId], fn ($v) => $v !== null);
        $conversation->update(['state' => $state]);
    }

    public static function rememberMachine(int $conversationId, Machine $machine): void
    {
        self::remember($conversationId, trim((string) $machine->name), CatalogService::engineCc($machine), CatalogService::kind($machine), (float) $machine->cash_price, $machine->id);
    }

    /** @return array{label: string, cc: int, kind: string, price?: float, id?: int}|null */
    public static function get(int $conversationId): ?array
    {
        return WhatsappConversation::find($conversationId)?->state['interest'] ?? null;
    }

    /** A size in a model name ("srk 250", "SRK 250"). */
    public static function ccIn(string $text): ?int
    {
        return preg_match('/(?<!\d)(100|110|125|135|150|160|180|200|220|250|300|400|500)(?!\d)/', \App\Support\ArabicTextNormalizer::normalize($text), $m) ? (int) $m[1] : null;
    }
}
