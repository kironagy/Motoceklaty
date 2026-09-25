<?php

namespace App\Domain\Knowledge;

use App\Models\BusinessMemory;
use Illuminate\Support\Collection;

/**
 * Reads dashboard-managed business knowledge (T08). No retrieval by
 * scanning customer text - selection is always by explicit scope
 * (customer type / application status) or by key, never by content match.
 */
class KnowledgeService
{
    /** Active + pinned, ordered by priority. */
    public function pinned(): Collection
    {
        return BusinessMemory::query()
            ->where('is_active', true)
            ->where('is_pinned', true)
            ->orderBy('priority')
            ->get();
    }

    /** Active + not pinned -> one line per entry: key => title. */
    public function index(): Collection
    {
        return BusinessMemory::query()
            ->where('is_active', true)
            ->where('is_pinned', false)
            ->orderBy('priority')
            ->pluck('title', 'key');
    }

    /** Active entries whose scope arrays contain the given values. */
    public function scoped(?string $customerType, ?string $applicationStatus): Collection
    {
        return BusinessMemory::query()
            ->where('is_active', true)
            ->get()
            ->filter(function (BusinessMemory $memory) use ($customerType, $applicationStatus) {
                $matchesType = $customerType && in_array($customerType, $memory->scope_customer_types ?? [], true);
                $matchesStatus = $applicationStatus && in_array($applicationStatus, $memory->scope_application_statuses ?? [], true);

                return $matchesType || $matchesStatus;
            })
            ->sortBy('priority')
            ->values();
    }

    /** @param string[] $keys */
    public function get(array $keys): Collection
    {
        return BusinessMemory::query()
            ->where('is_active', true)
            ->whereIn('key', $keys)
            ->get();
    }
}
