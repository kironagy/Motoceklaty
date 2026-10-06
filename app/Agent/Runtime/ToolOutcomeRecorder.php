<?php

namespace App\Agent\Runtime;

use App\Agent\Tools\ToolContext;
use App\Domain\Conversations\CustomerInterest;
use App\Domain\Conversations\QuotedMotorcycle;
use App\Domain\Conversations\QuotedOffer;
use App\Models\Machine;

/**
 * Rebuild: READ tools write nothing. What a successful lookup tells us about
 * the conversation - the motorcycle he is weighing, the size he is after,
 * the offer he was given with its numbers - is recorded here, by the runner,
 * once, after the result. The quotes become the turn-to-turn price memory
 * (QuotedOffer::ledger) that the next turns read instead of looking up again.
 */
class ToolOutcomeRecorder
{
    public function record(string $tool, array $args, array $result, ToolContext $ctx): void
    {
        if (! ($result['ok'] ?? false)) {
            return;
        }

        $data = (array) ($result['data'] ?? []);

        // Simulator 2026-10-05: three cash prices from a search, then "على
        // سنتين" - the next reply could not repeat them (no tool this turn) and
        // asked "أنهي نسخة؟" three times with no prices. What he was shown counts.
        if (in_array($tool, ['search_motorcycles', 'get_motorcycle_details', 'get_installment_offer'], true)) {
            $items = $tool === 'get_installment_offer'
                ? [['id' => (int) ($args['motorcycle_id'] ?? 0)]]
                : (array) ($data['items'] ?? []);
            QuotedOffer::addCashPrices($ctx->conversationId, array_values(array_filter(array_map(fn ($i) => (int) ($i['id'] ?? 0), $items))));
        }

        try {
            match ($tool) {
                'get_motorcycle_details' => count((array) ($args['motorcycle_ids'] ?? [])) === 1
                    ? QuotedMotorcycle::remember($ctx->conversationId, (int) $args['motorcycle_ids'][0]) : null,
                'calculate_installment' => QuotedMotorcycle::remember($ctx->conversationId, (int) $args['motorcycle_id']),
                'get_installment_offer' => $this->offer($args, $data, $ctx),
                'search_motorcycles' => $this->interestFromSearch($args, $data, $ctx),
                'identify_motorcycle_from_image' => $this->interestFromPhoto($data, $ctx),
                default => null,
            };
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Tool outcome not recorded', ['tool' => $tool, 'error' => $e->getMessage()]);
        }
    }

    private function offer(array $args, array $data, ToolContext $ctx): void
    {
        $machineId = (int) ($args['motorcycle_id'] ?? 0);
        QuotedMotorcycle::remember($ctx->conversationId, $machineId);

        $offers = (array) ($data['offers'] ?? []);

        foreach ($offers as $offer) {
            QuotedOffer::addToLedger($ctx->conversationId, $machineId, (array) $offer);
        }

        // one duration = what he is weighing (start_application picks it up)
        if (count($offers) === 1 && isset($offers[0]['plan_id'])) {
            QuotedOffer::remember($ctx->conversationId, [
                'machine_id' => $machineId, 'months' => (int) $offers[0]['months'],
                'plan_id' => (int) $offers[0]['plan_id'], 'down_payment' => (float) ($offers[0]['down_payment'] ?? 0),
            ]);
        }
    }

    private function interestFromSearch(array $args, array $data, ToolContext $ctx): void
    {
        $name = trim((string) ($args['name_query'] ?? ''));

        if ($name === '') {
            return;
        }

        if (($data['not_carried'] ?? false) && ($cc = CustomerInterest::ccIn($name)) !== null) {
            CustomerInterest::remember($ctx->conversationId, $name, $cc);
        } elseif (count((array) ($data['items'] ?? [])) === 1 && ($machine = Machine::with('brand')->find($data['items'][0]['id'] ?? 0))) {
            CustomerInterest::rememberMachine($ctx->conversationId, $machine);
        }
    }

    private function interestFromPhoto(array $data, ToolContext $ctx): void
    {
        $observed = (array) ($data['observed'] ?? []);
        $model = trim((string) ($observed['model'] ?? ''));

        if (($data['band'] ?? null) === 'not_in_catalog' && ($cc = CustomerInterest::ccIn($model)) !== null) {
            $kind = str_contains(mb_strtolower((string) ($observed['style'] ?? '')), 'scooter') ? 'scooter' : 'motorcycle';
            CustomerInterest::remember($ctx->conversationId, trim(($observed['brand'] ?? '').' '.$model), $cc, $kind);
        } elseif (($data['band'] ?? null) === 'match' && ($top = ($data['candidates'][0] ?? null)) && ($machine = Machine::with('brand')->find($top['motorcycle_id'] ?? 0))) {
            CustomerInterest::rememberMachine($ctx->conversationId, $machine);
        }
    }
}
