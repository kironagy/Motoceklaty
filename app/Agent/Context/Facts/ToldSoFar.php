<?php

namespace App\Agent\Context\Facts;

use App\Models\AiTraceStep;
use App\Models\Application;
use App\Models\Machine;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;

/**
 * Phase 1: what the customer was ALREADY told, so "تمام" is not answered by
 * saying it all again. Built only from authoritative records - successful
 * tool calls in the trace and what our own sent messages carry in their
 * metadata. No text of any message is read.
 *
 * Prices are not repeated here: an offer in the facts' `quotes` / `cash_prices`
 * IS a price he was told (both come from the ledger a tool wrote), and the
 * instructions say so. This block holds what is told nowhere else.
 */
class ToldSoFar
{
    /** @return array<string, mixed> empty keys are omitted */
    public function for(WhatsappConversation $conversation, ?Application $application): array
    {
        $steps = AiTraceStep::query()
            ->whereIn('tool_name', ['get_application_requirements', 'start_application', 'get_branch_information'])
            ->whereNull('result_code')
            ->whereHas('trace', fn ($q) => $q->where('conversation_id', $conversation->id))
            ->get(['tool_name', 'result_redacted'])
            ->filter(fn ($step) => ($step->result_redacted['ok'] ?? false) === true)
            ->pluck('tool_name')
            ->unique();

        return array_filter([
            'requirements_listed' => $this->requirementsListed($conversation, $application, $steps) ? true : null,
            'bike_photos_sent' => $this->photosSent($conversation),
            'branch_info_given' => $steps->contains('get_branch_information') ? true : null,
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * The list of papers/data was looked up, or a reply of ours went out
     * after the application opened (the old `full_list_sent` signal).
     */
    private function requirementsListed(WhatsappConversation $conversation, ?Application $application, $steps): bool
    {
        if ($steps->contains('get_application_requirements') || $steps->contains('start_application')) {
            return true;
        }

        return $application !== null && WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('direction', 'outgoing')->where('sender_type', 'bot')
            ->where('created_at', '>=', $application->created_at)->exists();
    }

    /** @return list<string> names of motorcycles whose catalog photos reached him */
    private function photosSent(WhatsappConversation $conversation): array
    {
        $ids = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('direction', 'outgoing')->where('type', '!=', 'text')
            ->where(fn ($q) => $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'failed'))
            ->latest('id')->limit(40)->get(['metadata'])
            ->map(fn ($m) => (int) ($m->metadata['motorcycle_id'] ?? 0))
            ->filter()->unique()->values();

        return $ids->isEmpty() ? [] : Machine::whereIn('id', $ids)->orderBy('id')->pluck('name')->map(fn ($n) => trim((string) $n))->values()->all();
    }
}
