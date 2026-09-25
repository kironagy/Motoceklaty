<?php

namespace App\Agent\Tools;

use App\Domain\Catalog\CatalogService;

/** READ — plan §6.2. Also how motorcycles are compared. */
class GetMotorcycleDetailsTool implements Tool
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
            .'Do not use when you only need to know that a model exists.';
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

        if (count($args['motorcycle_ids']) === 1) {
            \App\Domain\Conversations\QuotedMotorcycle::remember($ctx->conversationId, (int) $args['motorcycle_ids'][0]);
        }

        return ToolResult::ok(['items' => $result['items']]);
    }
}
