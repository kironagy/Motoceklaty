<?php

namespace App\Agent\Tools;

use App\Domain\Applications\SubmissionException;
use App\Domain\Applications\SubmissionService;
use App\Models\Application;

/** DESTRUCTIVE — plan §6.13 */
class SubmitApplicationTool implements WriteTool
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
        return 'Send the application to staff. Call it as soon as nothing is missing - it sends it at once (submitted=true + reference). '
            .'Only when the customer himself asked to see his data/summary does it send him the stored summary instead and return '
            .'CUSTOMER_CONFIRMATION_REQUIRED; his OK after that = call again with confirm=true. Missing items = NOT_READY with the blockers - '
            .'never say it was sent unless submitted=true, and never write a summary yourself.';
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

        // Owner 2026-10-07: a business name on his tax card he never wrote ("أكلات المعلم" for "جبل الحلال")
        if ($name = app(\App\Domain\Documents\BusinessNameCheck::class)->pending($application)) {
            return ToolResult::error('BUSINESS_NAME_UNCONFIRMED', 'His tax card names the business "'.$name.'", which he never said. Ask him in one line, with that exact name: "البطاقة الضريبية باسم نشاط «'.$name.'»، ده نفس نشاط حضرتك؟" - then call again.');
        }

        // Owner 2026-10-07: what he does exactly is on every request - asked here too if it was missed
        if ($application->origin_conversation_id && ($problem = app(\App\Domain\Applications\WorkClassification::class)->exactJobMissing((int) $application->origin_conversation_id))) {
            return ToolResult::error($problem['code'], $problem['hint']);
        }

        try {
            $result = $this->submissions->submit($application, $ctx->turnId, ($args['confirm'] ?? false) === true, $this->askedForSummary($ctx));
        } catch (SubmissionException $e) {
            return ToolResult::error($e->errorCode, $e->getMessage() !== $e->errorCode ? $e->getMessage() : '');
        }

        if ($result['submitted'] === true) {
            return ToolResult::ok(['submitted' => true, 'reference' => $result['reference']]
                + (($result['resubmitted'] ?? false) ? ['resubmitted' => true] : []));
        }

        // Not submitted is never a success. The summary is sent by Laravel,
        // verbatim from the DB, right after the AI's own message.
        $ctx->outbound->addTrailingMessage($result['review_text']);

        // QA 2026-10-04: the line before the summary said "ناقص الرقم القومي ونوع
        // الشغل" while the summary showed them recorded (✓), and an "اه" after
        // it got "عايز أقدّم دلوقتي؟" twice more.
        // what the reply around the summary is: the instructions (§٨)
        return ToolResult::error('CUSTOMER_CONFIRMATION_REQUIRED', 'Not submitted yet. The stored summary is sent to him right after your message.');
    }

    /**
     * Owner 2026-10-07: the summary only when HE asked to see it ("ابعتلي الملخص", "وريني
     * البيانات") - read from his own messages since the last reply, not from the model.
     */
    private function askedForSummary(ToolContext $ctx): bool
    {
        $said = app(\App\Agent\Runtime\ReplyGuard::class)->customerTextSinceLastReply(\App\Models\WhatsappConversation::findOrFail($ctx->conversationId));

        return (bool) preg_match('/ملخص|راجع|مراجع[ةه]|(?:وريني|ورينى|اعرض|ابعتلي|ابعتلى|اشوف|أشوف)\s+(?:\S+\s+)?(?:ال)?(?:بيانات|طلب|داتا|اللي\s+(?:اتسجل|سجلت))|(?:البيانات|بياناتي)\s+(?:اللي\s+)?(?:اتسجلت|سجلتها|متسجل[ةه]?)/u', $said);
    }
}
