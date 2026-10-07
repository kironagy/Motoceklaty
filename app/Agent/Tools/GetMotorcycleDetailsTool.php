<?php

namespace App\Agent\Tools;

use App\Domain\Catalog\CatalogService;

/** READ — plan §6.2. Also how motorcycles are compared. */
class GetMotorcycleDetailsTool implements ReadTool
{
    public function __construct(private readonly CatalogService $catalog)
    {
    }

    public function name(): string
    {
        return 'get_motorcycle_details';
    }

    public function description(): string
    {
        return 'Authoritative details and prices for 1-3 motorcycles. Use before stating any price, spec, '
            .'color or offer, or when comparing models - call it again in every turn the customer asks about a spec, even if an earlier turn called it. '
            .'`specifications` is the showroom\'s own spec sheet (key: value) and wins over any other source. '
            .'Do not use when you only need to know that a model exists. With 2-3 models `difference` is their real difference from the showroom data - '
            .'when he asks "ايه الفرق؟" say it in your words and add nothing it does not hold.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['motorcycle_ids'],
            'properties' => [
                'motorcycle_ids' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 3,
                    'items' => ['type' => 'integer'],
                ],
            ],
        ];
    }

    public function permission(): string
    {
        return 'READ';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $result = $this->catalog->details($args['motorcycle_ids']);

        if ($result['unknown_ids'] !== []) {
            return ToolResult::error('UNKNOWN_MOTORCYCLE', 'Unknown id(s): '.implode(', ', $result['unknown_ids']));
        }

        return ToolResult::ok(['items' => $result['items']] + (count($result['items']) > 1 ? ['difference' => $this->difference($result['items'])] : []));
    }

    /**
     * Server comparison 2026-10-07: "ايه الفرق بين ال43 وال50؟" got "جودة الخامات والتقفيل" or nothing.
     * The difference is computed from the showroom's own data - price, size, and what each one lists
     * that the other does not - and handed over as one line to say.
     */
    private function difference(array $items): string
    {
        $features = fn (array $item) => array_values(array_filter(array_map('trim', (array) ($item['features'] ?? []))));
        $parts = [];

        foreach ($items as $item) {
            $others = collect($items)->reject(fn ($o) => $o['id'] === $item['id'])->flatMap($features)->all();
            $own = array_values(array_diff($features($item), $others));
            $price = $item['is_offer'] && $item['new_price'] ? $item['new_price'] : $item['cash_price'];
            $parts[] = trim($item['name']).' سعرها كاش '.number_format((float) $price).' جنيه'
                .($item['cc'] ? '، '.$item['cc'].' سي سي' : '')
                .($own !== [] ? '، ومكتوب فيها: '.implode('، ', $own) : '');
        }

        return implode(' - ', $parts).'.';
    }
}
