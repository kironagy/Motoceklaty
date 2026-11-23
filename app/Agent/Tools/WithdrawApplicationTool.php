<?php

namespace App\Agent\Tools;

use App\Domain\Applications\ApplicationService;
use App\Domain\Applications\ApplicationTransitionException;
use App\Models\Application;

/** DESTRUCTIVE — plan §6.14 */
class WithdrawApplicationTool implements WriteTool
{
    /** تحت الاستعلام / استعلام عمل: the request is with the company. */
    public const AT_COMPANY_STATUSES = ['pending', 'work_check'];

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
            .'only changing motorcycle or plan (use update_application_selection). '
            .'Fails with REQUEST_AT_COMPANY when the request is already under inquiry at the company: then follow the error detail.';
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

        // Owner 2026-11-23: a request already at the company (under inquiry) can't be cancelled from
        // here - the customer cancels it with Aman's customer service.
        $request = $application->installment_request_id ? \App\Models\InstallmentRequest::find($application->installment_request_id) : null;

        if ($request && in_array($request->status, self::AT_COMPANY_STATUSES, true)) {
            return ToolResult::error('REQUEST_AT_COMPANY', 'The request is already under inquiry at the company and cannot be cancelled here. '
                .'Do not say it was cancelled. Tell the customer exactly: "للأسف طلبك دخل الشركة ومش هقدر ألغيه من عندي، تقدر تكلم خدمة عملاء أمان 19910"');
        }

        try {
            $this->applications->withdraw($application, $args['reason_code'], $args['note'] ?? null);
        } catch (ApplicationTransitionException $e) {
            return ToolResult::error($e->errorCode);
        }

        return ToolResult::ok(['status' => 'withdrawn']);
    }
}
