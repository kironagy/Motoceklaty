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
            .'obvious from the catalog index. Do not use when the ID is already known (use get_motorcycle_details).';
    }

    /** A size in a model name the customer typed ("srk 200"). */
    private function ccIn(string $query): ?int
    {
        return preg_match('/(?<!\d)(100|110|125|150|160|180|200|220|250|300|400)(?!\d)/', \App\Support\ArabicTextNormalizer::normalize($query), $m) ? (int) $m[1] : null;
    }

    /** "ايه الموجود؟": no name, brand, size, price, color or offer filter. */
    private function isGeneric(array $args): bool
    {
        return array_intersect_key(array_filter($args, fn ($v) => $v !== null && $v !== '' && $v !== false),
            array_flip(['name_query', 'brand', 'cc_min', 'cc_max', 'max_cash_price', 'max_installment_price', 'offers_only', 'color', 'sort'])) === [];
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name_query' => ['type' => 'string', 'maxLength' => 60],
                'brand' => ['type' => 'string'],
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

    public function permission(): string
    {
        return 'READ';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        if (isset($args['cc_min'], $args['cc_max']) && $args['cc_min'] > $args['cc_max']) {
            return ToolResult::error('INVALID_ARGUMENTS', 'cc_min must be <= cc_max');
        }

        $result = $this->catalog->search($args);
        $conversation = \App\Models\WhatsappConversation::find($ctx->conversationId);
        $state = $conversation?->state ?? [];

        // A model we do not carry: what he gets instead is the same kind and
        // size, and that is remembered for a later "ايه الموجود؟".
        if (filled($args['name_query'] ?? null) && $result['items'] === []) {
            $cc = $this->ccIn((string) $args['name_query']);

            if ($cc !== null) {
                $state['interest'] = ['label' => (string) $args['name_query'], 'cc' => $cc, 'kind' => null];
                $result['not_carried'] = true;
                $result['similar_available'] = $this->catalog->similar($cc, 'motorcycle', null, 5);
                $result['note'] = 'We do not carry "'.$args['name_query'].'". He wants about '.$cc.'cc: if he asks what we have, offer similar_available '
                    .'(same size) - never other sizes or kinds unless he asks for them.';
            }
        } elseif (filled($args['name_query'] ?? null) && count($result['items']) === 1) {
            // he named a model we have: its kind and size is what he is after
            $found = \App\Models\Machine::with('brand')->find($result['items'][0]['id']);
            $state['interest'] = ['label' => $found->name, 'cc' => \App\Domain\Catalog\CatalogService::engineCc($found),
                'kind' => \App\Domain\Catalog\CatalogService::kind($found), 'price' => (float) $found->cash_price, 'id' => $found->id];
        } elseif ($this->isGeneric($args) && ($interest = $state['interest'] ?? null) && ($interest['cc'] ?? null)) {
            // "ايه الموجود حاليا؟" right after asking about a 200cc model
            $result['items'] = $this->catalog->similar((int) $interest['cc'], $interest['kind'] ?? 'motorcycle', $interest['price'] ?? null,
                (int) min(8, $args['limit'] ?? 5), array_filter([$interest['id'] ?? null]));
            $result['note'] = 'He was asking about '.$interest['label'].' (about '.$interest['cc'].'cc): these are the closest we have. '
                .'Offer these; other sizes or kinds only if he asks for something different.';
        }

        if ($conversation && $state !== ($conversation->state ?? [])) {
            $conversation->update(['state' => $state]);
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
