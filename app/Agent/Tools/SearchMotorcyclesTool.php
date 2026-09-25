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

        // Engine size is not recorded for every motorcycle: "150cc" matched
        // nothing and the model guessed a list from names, missing half of
        // them. Say how many were outside the filter because cc is unknown.
        if (isset($args['cc_min']) || isset($args['cc_max'])) {
            $unknownCc = \App\Models\Machine::where('is_active', true)->whereNull('cc')->count();

            if ($unknownCc > 0) {
                $result['cc_unknown_count'] = $unknownCc;
                $result['cc_note'] = "{$unknownCc} active motorcycles have no cc recorded and were not matched by the cc filter. "
                    .'Search by name (e.g. name_query "150") too, and do not say a size is unavailable based on this filter alone.';
            }
        }

        return ToolResult::ok($result);
    }
}
