<?php

namespace App\Agent\Tools;

use App\Domain\Catalog\CatalogService;

/** READ — plan §6.1 */
class SearchMotorcyclesTool implements Tool
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
            .'obvious from the catalog index. Do not use when the ID is already known (use get_motorcycle_details). '
            .'Each item has its kind (سكوتر / موتوسيكل ...): offer only the kind he asked for.';
    }

    /** "ايه الموجود؟": no name, brand, size, price, color or offer filter. */
    private function isGeneric(array $args): bool
    {
        return array_intersect_key(array_filter($args, fn ($v) => $v !== null && $v !== '' && $v !== false),
            array_flip(['name_query', 'brand', 'kind', 'cc_min', 'cc_max', 'max_cash_price', 'max_installment_price', 'offers_only', 'color', 'sort'])) === [];
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name_query' => ['type' => 'string', 'maxLength' => 60],
                'brand' => ['type' => 'string'],
                'kind' => ['type' => 'string', 'enum' => \App\Domain\Catalog\CatalogService::KINDS,
                    'description' => 'The kind of vehicle he asked for: scooter (سكوتر/اسكوتر), electric (سكوتر كهربا), motorcycle, tricycle (تروسيكل). Always set it when he said one.'],
                'cc_min' => ['type' => 'integer', 'minimum' => 0],
                'cc_max' => ['type' => 'integer', 'minimum' => 0],
                'max_cash_price' => ['type' => 'number', 'minimum' => 0],
                'max_installment_price' => ['type' => 'number', 'minimum' => 0],
                'offers_only' => ['type' => 'boolean'],
                'color' => ['type' => 'string', 'description' => 'Color the customer asked for, in their words (e.g. الحمرا, اسود, blue).'],
                'available_only' => ['type' => 'boolean'],
                'sort' => ['type' => 'string', 'enum' => ['cheapest', 'most_expensive'], 'description' => 'Use cheapest when the customer wants something cheap or asks for the lowest price.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8],
            ],
        ];
    }

    private function customerSaidScooter(int $conversationId): bool
    {
        return \App\Models\WhatsappMessage::where('whatsapp_conversation_id', $conversationId)
            ->where('direction', 'incoming')->latest('id')->limit(6)->pluck('text')
            ->contains(fn ($t) => preg_match('/[اإ]?سكوت[رير]|scooter|فيسبا|vespa/iu', (string) $t));
    }

    public function permission(): string
    {
        return 'READ';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        if (isset($args['cc_min'], $args['cc_max']) && $args['cc_min'] > $args['cc_max']) {
            return ToolResult::error('INVALID_ARGUMENTS', 'cc_min must be <= cc_max');
        }

        // QA 2026-10-04: "عايز مكنة اشتغل عليها دليفري" got four scooters - in
        // Egypt "مكنة" is a motorcycle. Scooters only when he said scooter.
        if (($args['kind'] ?? null) === 'scooter' && ! $this->customerSaidScooter($ctx->conversationId)) {
            $args['kind'] = 'motorcycle';
        }

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

        // A model we do not carry: what he gets instead is the same kind and
        // size, and that is remembered for a later "ايه الموجود؟".
        if (filled($args['name_query'] ?? null) && $result['items'] === []) {
            $cc = $interest::ccIn((string) $args['name_query']);

            if ($cc !== null) {
                $interest::remember($ctx->conversationId, (string) $args['name_query'], $cc);
                $result['not_carried'] = true;
                $result['similar_available'] = $this->catalog->similar($cc, 'motorcycle', null, 5);
                $result['note'] = 'We do not carry "'.$args['name_query'].'". He wants about '.$cc.'cc: if he asks what we have, offer similar_available '
                    .'(same size) - never other sizes or kinds unless he asks for them.';
            }
        } elseif (filled($args['name_query'] ?? null) && count($result['items']) === 1) {
            // he named a model we have: its kind and size is what he is after
            $interest::rememberMachine($ctx->conversationId, \App\Models\Machine::with('brand')->find($result['items'][0]['id']));
        } elseif ($this->isGeneric($args) && ($wanted = $interest::get($ctx->conversationId)) && ($wanted['cc'] ?? null)) {
            // "ايه الموجود حاليا؟" right after asking about a 250cc model
            $result['items'] = $this->catalog->similar((int) $wanted['cc'], $wanted['kind'] ?? 'motorcycle', $wanted['price'] ?? null,
                (int) min(8, $args['limit'] ?? 5), array_filter([$wanted['id'] ?? null]));
            $result['note'] = 'He was asking about '.$wanted['label'].' (about '.$wanted['cc'].'cc): these are the closest we have. '
                .'Offer these; other sizes or kinds only if he asks for something different.';
        }

        // "VLR 200" is sold by two brands at different prices; picking one
        // silently quoted the wrong price. Flag same-name hits from
        // different brands so the agent asks which one.
        $sameName = collect($result['items'])
            ->groupBy(fn ($i) => mb_strtolower(preg_replace('/\s+/u', '', (string) $i['name'])))
            ->filter(fn ($group) => $group->pluck('brand')->unique()->count() > 1);

        if ($sameName->isNotEmpty()) {
            $result['ambiguous_names'] = $sameName->map(fn ($group) => $group->map(fn ($i) => "{$i['brand']} (id {$i['id']})")->values())->values()->all();
            $result['note'] = 'Same model name from different brands - ask the customer which brand before quoting a price.';
        }

        return ToolResult::ok($result);
    }
}
