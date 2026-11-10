<?php

namespace App\Agent\Tools;

use App\Domain\Catalog\CatalogService;

/** READ — plan §6.1 */
class SearchMotorcyclesTool implements ReadTool
{
    public function __construct(private readonly CatalogService $catalog)
    {
    }

    public function name(): string
    {
        return 'search_motorcycles';
    }

    public function description(): string
    {
        return 'Find catalog motorcycles by structured filters or a name the AI extracted. '
            .'Use when the customer asks what is available, gives a budget/cc/brand, or names a model not '
            .'obvious from the catalog index (or no index is shown) - name_query also matches showroom nicknames (النحلة، هوجن جمبو). Do not use when the ID is already known (use get_motorcycle_details). '
            .'Each item has its kind (سكوتر / موتوسيكل ...): offer only the kind he asked for.';
    }

    /** "ايه الموجود؟": no name, brand, size, price, color or offer filter. */
    private function isGeneric(array $args): bool
    {
        return array_intersect_key(array_filter($args, fn ($v) => $v !== null && $v !== '' && $v !== false),
            array_flip(['name_query', 'brand', 'kind', 'style', 'cc_min', 'cc_max', 'max_cash_price', 'min_cash_price', 'max_installment_price', 'offers_only', 'color', 'sort'])) === [];
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name_query' => ['type' => 'string', 'maxLength' => 60],
                'brand' => ['type' => 'string'],
                'kind' => ['type' => 'string', 'enum' => \App\Domain\Catalog\CatalogService::KINDS,
                    'description' => 'The kind he asked for: scooter (سكوتر/اسكوتر), electric (سكوتر كهربا), motorcycle (مكنة/موتوسيكل/دليفري - in Egypt مكنة is a motorcycle), tricycle (تروسيكل/تروسكل only). "تريلا/تريل/مكنة عالية" is a trail MOTORCYCLE, never a tricycle. Leave it out when he did not say a kind.'],
                'style' => ['type' => 'string', 'enum' => array_keys(\App\Domain\Catalog\CatalogService::STYLE_CATEGORIES),
                    'description' => 'Motorcycle style he asked for: trail (تريلا/تريل/مكنة عالية/أوف رود/كروس - tall trail bikes like KTX 250, F16), sport (سبورت/رياضي), naked, cruiser (كروزر/شوبر), commuter (عادي/شغل). Leave it out when he did not say a style.'],
                'cc_min' => ['type' => 'integer', 'minimum' => 0],
                'cc_max' => ['type' => 'integer', 'minimum' => 0],
                'max_cash_price' => ['type' => 'number', 'minimum' => 0, 'description' => 'His budget ("في حدود 60" = 60000; "60 او 65" = 65000). Results come nearest the budget first.'],
                'min_cash_price' => ['type' => 'number', 'minimum' => 0, 'description' => 'Only for a range he gave ("من 50 لـ 60" = 50000).'],
                'max_installment_price' => ['type' => 'number', 'minimum' => 0],
                'offers_only' => ['type' => 'boolean'],
                'color' => ['type' => 'string', 'description' => 'Color the customer asked for, in their words (e.g. الحمرا, اسود, blue).'],
                'available_only' => ['type' => 'boolean'],
                'sort' => ['type' => 'string', 'enum' => ['cheapest', 'most_expensive'], 'description' => 'Use cheapest when the customer wants something cheap or asks for the lowest price.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8],
            ],
        ];
    }



    public function permission(): string
    {
        return 'READ';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        // gpt-5-nano fills every optional filter: cc_max 0, brand "", min
        // price 0. "cc_max: 0" alone emptied the search and a girl with a
        // 65,000 budget was told nothing fits. An empty or zero filter is no filter.
        $args = array_filter($args, fn ($v) => $v !== null && $v !== '' && $v !== 0 && $v !== 0.0 && $v !== '0');

        // The kind is the model's reading of his words (see the argument); the tool trusts it.
        // a budget means nearest the budget, not the cheapest under it
        if (isset($args['max_cash_price']) && ($args['sort'] ?? null) === 'cheapest') {
            unset($args['sort']);
        }

        if (isset($args['cc_min'], $args['cc_max']) && $args['cc_min'] > $args['cc_max']) {
            return ToolResult::error('INVALID_ARGUMENTS', 'cc_min must be <= cc_max');
        }

        // Public product questions use the active catalog, never physical stock.
        $args['available_only'] = false;
        $result = $this->catalog->search($args);
        $interest = \App\Domain\Conversations\CustomerInterest::class;

        // QA 2026-10-04: "اللايفان ده" - the Lifan 150 scooter just listed -
        // was searched as kind=motorcycle and he was told "مش عندنا". A model
        // he names is looked up whatever kind the model guessed; its real
        // kind comes back on the item.
        // Same for a guessed brand: "Keeway keet 150" is filed under "Scooters".
        if (filled($args['name_query'] ?? null) && (filled($args['kind'] ?? null) || filled($args['brand'] ?? null)) && $result['items'] === []) {
            $anyKind = $this->catalog->search(array_diff_key($args, ['kind' => true, 'brand' => true]));

            if ($anyKind['items'] !== []) {
                $result = $anyKind + ['note_kind' => 'This model is a '.($anyKind['items'][0]['kind'] ?? '').' - not the kind searched. Say its kind plainly.'];
            }
        }

        // brand scoping first: a scope that leaves nothing must still end in the full catalog below
        $conversation = \App\Models\WhatsappConversation::find($ctx->conversationId);
        if ($conversation) {
            $customerText = app(\App\Agent\Runtime\ReplyGuard::class)->customerTextSinceLastReply($conversation);
            $result = $this->scopeToRequestedBrand($result, $customerText);
        }

        // No spelling matched the name he wrote. That is NOT "we do not carry it": live
        // 2026-11-06..09 "فيجوري ماكس" (26 Max), "كواي كيت" (Keeway keet), "HXL 150" (HLX 150)
        // were all told "مش متوفر". The whole catalog goes back and the model decides by
        // meaning which row he means; only when no row is that model is it not carried.
        if (filled($args['name_query'] ?? null) && $result['items'] === []) {
            $result['no_spelling_match'] = true;
            $result['full_catalog'] = $this->catalog->compactCatalog();
            $result['how_to_read'] = 'No catalog name is spelled like "'.$args['name_query'].'". full_catalog is EVERYTHING we sell. '
                .'Customers misspell, transliterate (Arabic/English), reorder letters, drop or add the brand, the year or the cc, '
                .'and use nicknames. Decide by meaning which row(s) he means and continue with that id (get_motorcycle_details). '
                .'If several rows fit, ask him which one, naming them. Say we do not carry it ONLY if no row is the same model; '
                .'then offer rows of the same kind and size from full_catalog.';
            $cc = $interest::ccIn((string) $args['name_query']);

            if ($cc !== null) {
                $result['size_in_his_words'] = $cc;
            }
        } elseif ($this->isGeneric($args) && ($wanted = $interest::get($ctx->conversationId)) && ($wanted['cc'] ?? null)) {
            // "ايه الموجود حاليا؟" right after asking about a 250cc model
            $result['items'] = $this->catalog->similar((int) $wanted['cc'], $wanted['kind'] ?? 'motorcycle', $wanted['price'] ?? null,
                (int) min(8, $args['limit'] ?? 5), array_filter([$wanted['id'] ?? null]));
            $result['closest_to'] = ['label' => $wanted['label'] ?? null, 'cc' => (int) $wanted['cc']];
        }

        // Live 2026-11-07..09: spelling hits are often only PART of what he means - "هوجن 250" found
        // only Tx 250 (L250, f250, H250, Z250, V250 MAX are Hogan 250s too), "دايو ٤ استيراد" missed
        // "دايو ٤ اصلي", brand="هوجان" found nothing. When he names a model or brand the whole catalog
        // comes with the hits, so the agent checks every row he could mean before it answers.
        if ((filled($args['name_query'] ?? null) || filled($args['brand'] ?? null)) && ! isset($result['full_catalog'])) {
            $result['full_catalog'] = $this->catalog->compactCatalog();
            $result['how_to_read'] = '`items` are spelling hits only. full_catalog is EVERYTHING we sell: before answering, check by meaning '
                .'which rows he could mean (other versions, other brands with that name, the same cc). If several fit, name them and ask which; '
                .'if one fits that is not in items, use it.';
        }

        // A budget, size or color with nothing inside it ("مكنة في حدود 25 لـ 30"): he still gets
        // the nearest real options, read from the catalog, instead of a bare "مفيش".
        if ($result['items'] === [] && ! isset($result['full_catalog'])) {
            $result['nothing_matches_these_filters'] = true;
            $result['full_catalog'] = $this->catalog->compactCatalog();
            $result['how_to_read'] = 'Nothing matches these exact filters. full_catalog is EVERYTHING we sell: tell him plainly what is nearest '
                .'to what he asked (closest price / size / kind) from it, with real prices; never say we have nothing.';
        }

        // "VLR 200" is sold by two brands at different prices; picking one
        // silently quoted the wrong price. Flag same-name hits from
        // different brands so the agent asks which one.
        $sameName = collect($result['items'])
            ->groupBy(fn ($i) => mb_strtolower(preg_replace('/\s+/u', '', (string) $i['name'])))
            ->filter(fn ($group) => $group->pluck('brand')->unique()->count() > 1);

        if ($sameName->isNotEmpty()) {
            $result['ambiguous_names'] = $sameName->map(fn ($group) => $group->map(fn ($i) => "{$i['brand']} (id {$i['id']})")->values())->values()->all();
        } elseif (($versions = $this->versionsOf($args, $result['items'])) !== null) {
            $result['versions'] = $versions;
        }

        return ToolResult::ok($result);
    }

    /** Scope broad model-name matches to a brand explicitly named this turn. */
    private function scopeToRequestedBrand(array $result, string $customerText): array
    {
        $ids = [];
        $normalized = \App\Support\ArabicTextNormalizer::normalize($customerText);
        foreach (preg_split('/[^\\p{L}\\p{N}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $ids = array_merge($ids, \App\Domain\Conversations\MentionedMotorcycle::brandIdsFor($word));
        }
        $brands = \App\Models\Brand::whereIn('id', array_unique($ids))
            ->whereNotIn('name', ['Scooters', 'Electric scooters', 'تروسيكلات'])->pluck('name')->all();
        if (count($brands) !== 1) return $result;
        $scoped = array_values(array_filter($result['items'] ?? [], fn ($item) => ($item['brand'] ?? null) === $brands[0]));
        // "كيواي اكس رود": the X Road is filed under "Scooters", not Keeway - a brand word that
        // matches none of the hits narrows nothing, it must not empty the search.
        if ($scoped === []) return $result;
        $result['items'] = $scoped;
        $result['total'] = count($result['items']);
        unset($result['ambiguous_names'], $result['versions'], $result['similar_available']);
        return $result;
    }

    /**
     * Owner 2026-10-06: "هوجن 3" comes as more than one version (استراد 75٪,
     * استراد بالكامل, فرز أول/تاني); the bot quoted one of them as if it were
     * the only one. A model he named (not a bare brand) that matched several
     * models of one brand = every version with its cash price.
     *
     * @return list<array{id: int, name: string, cash_price: mixed}>|null
     */
    private function versionsOf(array $args, array $items): ?array
    {
        $query = trim((string) ($args['name_query'] ?? ''));

        if ($query === '' || count($items) < 2 || count($items) > 6
            || \App\Domain\Conversations\MentionedMotorcycle::brandIdsFor($query) !== []
            || collect($items)->pluck('brand')->unique()->count() !== 1) {
            return null;
        }

        return array_map(fn ($i) => [
            'id' => $i['id'],
            'name' => $i['name'],
            'cash_price' => $i['is_offer'] && $i['offer_price'] ? $i['offer_price'] : $i['cash_price'],
        ], array_values($items));
    }
}
