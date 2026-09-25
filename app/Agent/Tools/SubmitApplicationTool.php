<?php

namespace App\Agent\Tools;

use App\Domain\Applications\SubmissionException;
use App\Domain\Applications\SubmissionService;
use App\Models\Application;

/** DESTRUCTIVE — plan §6.13 */
class SubmitApplicationTool implements Tool
{
    public function __construct(private readonly SubmissionService $submissions)
    {
    }

    public function name(): string
    {
        return 'submit_application';
    }

    public function description(): string
    {
        return 'Submit the application for staff review. Two steps, enforced by the system: the first call sends the '
            .'customer a summary of the stored data (added to your reply automatically) and returns '
            .'CUSTOMER_CONFIRMATION_REQUIRED - tell the customer to check it and confirm. Call again with confirm=true '
            .'only after the customer confirmed that summary in a later message. If anything is missing it returns '
            .'NOT_READY with the blockers - never tell the customer the application was sent unless this returns '
            .'submitted=true.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['confirm'],
            'properties' => [
                // Gemini's function-calling schema rejects a non-string enum value, so the
                // "must literally be true" constraint is enforced in execute() instead.
                'confirm' => ['type' => 'boolean', 'description' => 'true only when the customer confirmed the summary they were sent.'],
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
            $result = $this->submissions->submit($application, $ctx->turnId, ($args['confirm'] ?? false) === true);
        } catch (SubmissionException $e) {
            return ToolResult::error($e->errorCode, $e->getMessage() !== $e->errorCode ? $e->getMessage() : '');
        }

        if ($result['submitted'] === true) {
            return ToolResult::ok(['submitted' => true, 'reference' => $result['reference']]);
        }

        // Not submitted is never a success. The summary is sent by Laravel,
        // verbatim from the DB, right after the AI's own message.
        $ctx->outbound->addTrailingMessage($result['review_text']);

        return ToolResult::error('CUSTOMER_CONFIRMATION_REQUIRED', 'NOT submitted yet. The stored summary is being sent to the customer after your message - '
            .'ask them to check it and confirm (or say what to correct). Submit with confirm=true only after their confirmation. Summary: '
            .json_encode($result['review'], JSON_UNESCAPED_UNICODE));
    }
}
