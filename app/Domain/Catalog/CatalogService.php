<?php

namespace App\Domain\Catalog;

use App\Models\Machine;
use App\Models\MotorcycleImage;
use App\Support\ArabicTextNormalizer;
use App\Support\ColorNamer;
use App\Support\FuzzyArabicMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The authoritative catalog access layer (T09). Tools never read Machine
 * directly - this is the only place that knows the schema. The compact
 * index (indexLines) is for discovery only (plan principle 7); prices and
 * details always come from search()/details() in the same turn.
 */
class CatalogService
{
    private const INDEX_CACHE_KEY = 'catalog.index_lines';

    public function __construct(private readonly FuzzyArabicMatcher $fuzzy = new FuzzyArabicMatcher())
    {
    }

    public function search(array $filters): array
    {
        $query = Machine::query()->where('is_active', true);

        if ($filters['available_only'] ?? true) {
            $query->where('availability', '!=', 'out_of_stock');
        }

        if (! empty($filters['brand'])) {
            // "هوجن" never found the brand stored as "هوجان", and "سكوتر" found nothing.
            $brandIds = \App\Domain\Conversations\MentionedMotorcycle::brandIdsFor((string) $filters['brand']);
            $brandIds !== []
                ? $query->whereIn('brand_id', $brandIds)
                : $query->whereHas('brand', fn ($q) => $q->where('name', 'like', '%'.$filters['brand'].'%'));
        }

        if (isset($filters['cc_min'])) {
            $query->where('cc', '>=', $filters['cc_min']);
        }

        if (isset($filters['cc_max'])) {
            $query->where('cc', '<=', $filters['cc_max']);
        }

        if (isset($filters['max_cash_price'])) {
            $query->where('cash_price', '<=', $filters['max_cash_price']);
        }

        if (isset($filters['max_installment_price'])) {
            $query->where('installment_price', '<=', $filters['max_installment_price']);
        }

        if (! empty($filters['offers_only'])) {
            $query->where('type', 'offer');
        }

        $machines = $query->with('brand')->get();

        if (! empty($filters['name_query'])) {
            $machines = $this->filterByNameQuery($machines, $filters['name_query']);
        }

        // "عايز المكنة الحمرا" had no model name and search had no way to
        // ask by color, so the agent could only ask back.
        if (! empty($filters['color'])) {
            $machines = $machines->filter(fn (Machine $m) => MotorcycleImage::where('machine_id', $m->id)
                ->whereNotNull('color')
                ->pluck('color')
                ->contains(fn ($stored) => ColorNamer::matches($filters['color'], $stored)))->values();
        }

        // Unsorted, "اي حاجه رخيصه" got whatever 3 rows came first from the
        // DB - not the cheapest ones.
        if (in_array($filters['sort'] ?? null, ['cheapest', 'most_expensive'], true)) {
            $machines = $machines->sortBy(
                fn (Machine $m) => (float) ($m->type === 'offer' && $m->new_price ? $m->new_price : $m->cash_price),
                SORT_REGULAR,
                $filters['sort'] === 'most_expensive'
            )->values();
        }

        $limit = min(8, $filters['limit'] ?? 5);

        return [
            'items' => $machines->take($limit)->map(fn (Machine $m) => $this->toSearchItem($m))->values()->all(),
            'total' => $machines->count(),
        ];
    }

    private function filterByNameQuery(Collection $machines, string $nameQuery): Collection
    {
        $normalizedQuery = ArabicTextNormalizer::normalize($nameQuery);

        if ($normalizedQuery === '') {
            return $machines;
        }

        // A brand or category word alone ("سكوتر", "كهربا", "بينيلي") names
        // no model: it is every model of that brand.
        $brandIds = \App\Domain\Conversations\MentionedMotorcycle::brandIdsFor($nameQuery);

        if ($brandIds !== []) {
            return $machines->filter(fn (Machine $m) => in_array((int) $m->brand_id, $brandIds, true))->values();
        }

        return $machines->filter(function (Machine $machine) use ($normalizedQuery) {
            $candidates = array_merge([$machine->name], $machine->aliases ?? []);

            foreach ($candidates as $candidate) {
                $normalizedCandidate = ArabicTextNormalizer::normalize((string) $candidate);

                if ($normalizedCandidate === '') {
                    continue;
                }

                if (str_contains($normalizedCandidate, $normalizedQuery)
                    || str_contains($normalizedQuery, $normalizedCandidate)
                    || $this->fuzzy->containsFuzzyPhrase($normalizedCandidate, $normalizedQuery)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    private function toSearchItem(Machine $m): array
    {
        return [
            'id' => $m->id,
            'name' => $m->name,
            'brand' => $m->brand?->name,
            'cc' => $m->cc,
            'cash_price' => $m->cash_price,
            'installment_price' => $m->installment_price,
            'is_offer' => $m->type === 'offer',
            'offer_price' => $m->type === 'offer' ? $m->new_price : null,
            'availability' => $m->availability,
            // Without colors the agent told a customer "no 150 comes in
            // yellow" while the Wing 150 does.
            'colors' => $this->colorNames($m->id),
        ];
    }

    /**
     * @param  int[]  $ids
     */
    public function details(array $ids): array
    {
        $machines = Machine::query()->whereIn('id', $ids)->where('is_active', true)->with('brand')->get()->keyBy('id');
        $unknownIds = array_values(array_diff($ids, $machines->keys()->all()));

        $items = $machines->map(function (Machine $m) {
            $colors = collect($this->colorNames($m->id))
                ->map(fn ($color) => ['color' => $color, 'has_images' => true])
                ->all();

            return [
                'id' => $m->id,
                'name' => $m->name,
                'brand' => $m->brand?->name,
                'cash_price' => $m->cash_price,
                'installment_price' => $m->installment_price,
                'is_offer' => $m->type === 'offer',
                'old_price' => $m->type === 'offer' ? $m->old_price : null,
                'new_price' => $m->type === 'offer' ? $m->new_price : null,
                'features' => collect($m->features ?? [])->pluck('title')->filter()->values()->all(),
                'colors' => $colors,
                'availability' => $m->availability,
                'installment_system_ids' => $m->installmentSystemIds(),
                'cc' => $m->cc,
                'model_year' => $m->model_year,
                'description' => $m->description,
                'specifications' => $m->specifications,
            ];
        })->values()->all();

        return ['items' => $items, 'unknown_ids' => $unknownIds];
    }

    /**
     * Real images for one motorcycle, optionally one color. Gallery images
     * (is_display = false) come first; the listing's display image is the
     * fallback, because about half the catalog has nothing else and a
     * customer asking for a photo should still get one. A color is matched
     * by name ("احمر", "red") against the hex the dashboard stores.
     */
    public function images(int $machineId, ?string $color, int $max): array
    {
        $images = MotorcycleImage::where('machine_id', $machineId)
            ->orderBy('is_display')
            ->orderBy('sort')
            ->get();

        if ($color !== null) {
            $images = $images->filter(fn (MotorcycleImage $i) => $i->color !== null && ColorNamer::matches($color, $i->color));
        }

        $picked = $images->take($max)->values();

        return [
            'paths' => $picked->pluck('path')->all(),
            // each photo's own color, so a customer quoting "the white one" can be understood later
            'colors' => $picked->map(fn (MotorcycleImage $i) => $i->color !== null ? ColorNamer::name($i->color) : null)->all(),
            'colors_available' => $this->colorNames($machineId),
        ];
    }

    /** @return string[] distinct Arabic color names that have images */
    private function colorNames(int $machineId): array
    {
        return MotorcycleImage::where('machine_id', $machineId)
            ->whereNotNull('color')
            ->orderBy('sort')
            ->pluck('color')
            ->map(fn ($color) => ColorNamer::name($color))
            ->unique()
            ->values()
            ->all();
    }

    public function machineExists(int $id): bool
    {
        return Machine::where('id', $id)->where('is_active', true)->exists();
    }

    /** L3: one line per active motorcycle, discovery only (plan principle 7). */
    public function indexLines(): array
    {
        return Cache::rememberForever(self::INDEX_CACHE_KEY, function () {
            return Machine::query()
                ->where('is_active', true)
                ->with('brand')
                ->get()
                ->map(function (Machine $m) {
                    $parts = array_filter([
                        $m->id,
                        $m->brand?->name,
                        $m->name,
                        $m->cc ? "{$m->cc}cc" : null,
                        $m->cash_price !== null ? number_format((float) $m->cash_price).' كاش' : null,
                        $m->installment_price !== null ? number_format((float) $m->installment_price).' قسط' : null,
                    ]);

                    return implode(' · ', $parts);
                })
                ->values()
                ->all();
        });
    }

    public static function invalidateIndexCache(): void
    {
        Cache::forget(self::INDEX_CACHE_KEY);
    }
}
