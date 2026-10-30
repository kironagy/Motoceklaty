<?php

namespace App\Agent\Tools;

use App\Domain\Branches\BranchService;

/** READ — plan §6.15 */
class GetBranchInformationTool implements ReadTool
{
    public function __construct(private readonly BranchService $branches)
    {
    }

    public function name(): string
    {
        return 'get_branch_information';
    }

    public function description(): string
    {
        return (string) config('agent.branches.tool_description');
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'governorate' => ['type' => 'string', 'enum' => array_keys(config('agent.governorates', []))],
                'city' => ['type' => 'string', 'maxLength' => 60],
            ],
        ];
    }

    public function permission(): string
    {
        return 'READ';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $result = $this->branches->find(null, null);

        return ToolResult::ok($result);
    }
}
