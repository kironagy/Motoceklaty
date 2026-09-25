<?php

namespace App\Agent\Tools;

use App\Domain\Applications\ApplicationService;
use App\Domain\Applications\ApplicationTransitionException;
use App\Models\Application;

/** DESTRUCTIVE — plan §6.14 */
class WithdrawApplicationTool implements Tool
{
    public function __construct(private readonly ApplicationService $applications)
    {
    }

    public function name(): string
    {
        return 'withdraw_application';
    }

    public function description(): string
    {
        return 'Cancel the active application at the customer\'s explicit request. Do not use when they are '
            .'only changing motorcycle or plan (use update_application_selection).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['reason_code'],
            'properties' => [
                'reason_code' => ['type' => 'string', 'enum' => ['customer_request', 'changed_mind', 'other']],
                'note' => ['type' => 'string', 'maxLength' => 200],
            ],
        ];
    }

    public function permission(): string
    {
        return 'DESTRUCTIVE';
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $application = $ctx->activeApplicationId ? Application::find($ctx->activeApplicationId) : null;

        if (! $application) {
            return ToolResult::error('NO_ACTIVE_APPLICATION');
        }

        try {
            $this->applications->withdraw($application, $args['reason_code'], $args['note'] ?? null);
        } catch (ApplicationTransitionException $e) {
            return ToolResult::error($e->errorCode);
        }

        return ToolResult::ok(['status' => 'withdrawn']);
    }
}
