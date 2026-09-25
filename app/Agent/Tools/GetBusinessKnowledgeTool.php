<?php

namespace App\Agent\Tools;

use App\Domain\Knowledge\KnowledgeService;

/** READ — plan §6.16 */
class GetBusinessKnowledgeTool implements Tool
{
    public function __construct(private readonly KnowledgeService $knowledge)
    {
    }

    public function name(): string
    {
        return 'get_business_knowledge';
    }

    public function description(): string
    {
        return 'Fetch full content of knowledge entries listed in the L2 index. '
            .'Use when you need business explanation or guidance not already in context.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['keys'],
            'properties' => [
                'keys' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 4,
                    'items' => ['type' => 'string'],
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
        $found = $this->knowledge->get($args['keys']);
        $foundKeys = $found->pluck('key')->all();
        $unknown = array_diff($args['keys'], $foundKeys);

        if ($unknown !== []) {
            return ToolResult::error('UNKNOWN_KEY', 'Unknown key(s): '.implode(', ', $unknown));
        }

        return ToolResult::ok([
            'items' => $found->map(fn ($m) => [
                'key' => $m->key,
                'title' => $m->title,
                'content' => $m->content,
            ])->values()->all(),
        ]);
    }
}
