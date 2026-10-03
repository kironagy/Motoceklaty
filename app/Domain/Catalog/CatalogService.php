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
    private const INDEX_CACHE_KEY = 'catalog.index_lines.v2';

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

        // cc is empty for most rows ("250cc" sits in the name or features):
        // filtering on the column found almost nothing.
        if (isset($filters['cc_min']) || isset($filters['cc_max'])) {
            $machines = $machines->filter(function (Machine $m) use ($filters) {
                $cc = self::engineCc($m);

                return $cc !== null && $cc >= ($filters['cc_min'] ?? 0) && $cc <= ($filters['cc_max'] ?? PHP_INT_MAX);
            })->values();
        }

        if (! empty($filters['name_query'])) {
            $machines = $this->filterByNameQuery($machines, $filters['name_query']);
        }

        // 2026-10-03 (conversation 852): a girl asking for a scooter was
        // offered the Superlight cruiser "as a scooter" - search had no way
        // to keep to the kind of vehicle he asked for.
        if (in_array($filters['kind'] ?? null, self::KINDS, true)) {
            $machines = $machines->filter(fn (Machine $m) => self::kind($m) === $filters['kind'])->values();
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

    /** Engine size: the cc column, else a size in the name ("L250"), else in the features ("200 CC", "197 سم مكعب"). */
    public static function engineCc(Machine $machine): ?int
    {
        if ($machine->cc) {
            return (int) $machine->cc;
        }

        $name = ArabicTextNormalizer::normalize((string) $machine->name);

        if (preg_match('/(?<!\d)(100|110|125|135|150|160|180|197|200|220|250|300|400)(?!\d)/', $name, $m)) {
            return (int) $m[1];
        }

        $features = ArabicTextNormalizer::normalize(collect($machine->features ?? [])->pluck('title')->implode(' '));

        return preg_match('/(?<!\d)(\d{2,3})\s*(?:cc|سي ?سي|سم)/iu', $features, $m) ? (int) $m[1] : null;
    }

    public const KINDS = ['motorcycle', 'scooter', 'electric', 'tricycle'];

    private const CATEGORY_LABELS = [
        'commuter' => 'عادي', 'naked' => 'نيكد', 'sport' => 'سبورت', 'cruiser' => 'كروزر',
        'off_road' => 'أوف رود', 'touring' => 'تورينج',
    ];

    /** "سكوتر", "سكوتر كهربا", "تروسيكل", "موتوسيكل كروزر" - what the vehicle is, in the customer's words. */
    public static function kindLabel(Machine $machine): string
    {
        return match (self::kind($machine)) {
            'scooter' => 'سكوتر',
            'electric' => 'سكوتر كهربا',
            'tricycle' => 'تروسيكل',
            default => trim('موتوسيكل '.(self::CATEGORY_LABELS[$machine->category] ?? '')),
        };
    }

    /** motorcycle / scooter / tricycle / electric - the kind of vehicle, from its brand group. */
    public static function kind(Machine $machine): string
    {
        $brand = mb_strtolower(ArabicTextNormalizer::normalize((string) $machine->brand?->name));

        return match (true) {
            str_contains($brand, 'electric') || str_contains($brand, 'كهرب') => 'electric',
            str_contains($brand, 'scooter') || str_contains($brand, 'سكوتر') => 'scooter',
            str_contains($brand, 'تروسيك') || str_contains($brand, 'tricycle') => 'tricycle',
            default => 'motorcycle',
        };
    }

    /**
     * Simulator 690: asked for "srk 200" (not carried), then "ايه الموجود؟"
     * got a 39,000 Dayun and 250cc Hogans. What he gets is the same kind and
     * size first, closest in price.
     *
     * @return array<int, array> search items
     */
    public function similar(?int $cc, ?string $kind = null, ?float $price = null, int $limit = 5, array $excludeIds = []): array
    {
        return Machine::query()->where('is_active', true)->where('availability', 'in_stock')->with('brand')->get()
            ->reject(fn (Machine $m) => in_array($m->id, $excludeIds, true))
            ->filter(fn (Machine $m) => $kind === null || self::kind($m) === $kind)
            ->filter(fn (Machine $m) => $cc === null || (($own = self::engineCc($m)) !== null && abs($own - $cc) <= 25))
            ->sortBy(fn (Machine $m) => [
                $cc === null ? 0 : abs((self::engineCc($m) ?? 9999) - $cc),
                $price === null ? 0 : abs((float) $m->cash_price - $price),
            ])
            ->take($limit)
            ->map(fn (Machine $m) => $this->toSearchItem($m))
            ->values()->all();
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

        // 2026-10-02 (conversations 746, 763): "فيجوري L250" found only
        // Hogan's "L250" - the brand word was ignored, Vigory's "Vg L250" was
        // never matched and the customer was told we do not carry it. With a
        // brand in the query, that brand's models are searched first with
        // the rest of his words.
        $brandWords = [];
        $restWords = [];
        foreach (preg_split('/\s+/u', $normalizedQuery) as $word) {
            $ids = \App\Domain\Conversations\MentionedMotorcycle::brandIdsFor($word)
                ?: \App\Domain\Conversations\MentionedMotorcycle::brandIdsFor(preg_replace('/^ال(?=\p{Arabic}{2,})/u', '', $word));
            if ($ids !== []) {
                $brandWords = array_merge($brandWords, $ids);
            } else {
                $restWords[] = $word;
            }
        }
        $rest = trim(implode(' ', $restWords));
        if ($brandWords !== [] && $rest !== '') {
            $ofBrand = $this->matchName($machines->filter(fn (Machine $m) => in_array((int) $m->brand_id, $brandWords, true)), $rest);
            if ($ofBrand->isNotEmpty()) {
                return $ofBrand;
            }
        }

        return $this->matchName($machines, $normalizedQuery);
    }

    private function matchName(Collection $machines, string $normalizedQuery): Collection
    {
        // "Z250" also listed L250, f250, H250 and Vg L250 by fuzzy match: a
        // model he named exactly is the answer on its own.
        $exact = $machines->filter(function (Machine $machine) use ($normalizedQuery) {
            foreach (array_merge([$machine->name], $machine->aliases ?? []) as $candidate) {
                $normalizedCandidate = mb_strtolower(ArabicTextNormalizer::normalize((string) $candidate));
                if ($normalizedCandidate !== '' && ($normalizedCandidate === mb_strtolower($normalizedQuery)
                    || in_array(mb_strtolower($normalizedQuery), preg_split('/\s+/u', $normalizedCandidate), true))) {
                    return true;
                }
            }

            return false;
        })->values();

        if ($exact->isNotEmpty()) {
            return $exact;
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
            'kind' => self::kindLabel($m),
            'cc' => self::engineCc($m),
            'cash_price' => $m->cash_price,
            // no installment_price: the owner never tells the customer the
            // price the installment is calculated on
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
                'is_offer' => $m->type === 'offer',
                'old_price' => $m->type === 'offer' ? $m->old_price : null,
                'new_price' => $m->type === 'offer' ? $m->new_price : null,
                'features' => collect($m->features ?? [])->pluck('title')->filter()->values()->all(),
                'colors' => $colors,
                'availability' => $m->availability,
                'installment_system_ids' => $m->installmentSystemIds(),
                'cc' => self::engineCc($m),
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
                        self::kindLabel($m),
                        $m->cc ? "{$m->cc}cc" : null,
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
