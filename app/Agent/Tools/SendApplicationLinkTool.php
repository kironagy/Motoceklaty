<?php

namespace App\Agent\Tools;

use App\Domain\Forms\FormLinkException;
use App\Domain\Forms\FormLinkService;
use App\Domain\Forms\FormSubmissionService;
use App\Models\FormLink;
use App\Models\InstallmentRequest;
use App\Models\WhatsappConversation;

/**
 * Dynamic Links (spec §4.7): the only way the bot takes an application - it sends the
 * customer his form link. The link goes out as its own message right after the reply
 * (AgentRunner::withApplicationLink); the bot only introduces it.
 */
class SendApplicationLinkTool implements WriteTool
{
    public function __construct(private readonly FormLinkService $links)
    {
    }

    public function name(): string
    {
        return 'send_application_link';
    }

    public function description(): string
    {
        return 'He wants to apply for installments (or asks how to apply / asks for the link again): this sends him the '
            .'application form link - the link itself is sent automatically right after your reply, never write a URL yourself. '
            .'Your reply only says in one or two lines that this is the application link, that he fills his data and uploads his '
            .'papers on it, and that you are here if anything is unclear. Pass only what HE said in this conversation: his name, '
            .'the motorcycle (motorcycle_id from a tool), the months. Never collect his data or papers in the chat. '
            .'Same link again when asked again (what he wrote is kept). If it returns has_open_request, do not send a link: '
            .'tell him his request is under review.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer_name' => ['type' => 'string', 'maxLength' => 80, 'description' => 'Only the name he wrote himself (e.g. "كريم سامي"). Omit it when he never said his name - never "العميل" or a placeholder.'],
                'motorcycle_id' => ['type' => 'integer', 'description' => 'Only a motorcycle he chose, id from a catalog tool.'],
                'months' => ['type' => 'integer', 'description' => 'Only months he chose.'],
            ],
        ];
    }

    public function permission(): string
    {
        return 'WRITE';
    }

    /** A request of his still waiting for a decision: no new link while it does. */
    public function openRequest(WhatsappConversation $conversation): ?InstallmentRequest
    {
        return app(\App\Domain\Applications\RequestIdentity::class)->query($conversation->customer, $conversation)
            ->whereIn('status', FormSubmissionService::UNDECIDED_STATUSES)->latest('id')->first();
    }

    public function maySendLink(WhatsappConversation $conversation): bool
    {
        if ($this->openRequest($conversation)) return false;
        $text = app(\App\Agent\Runtime\ReplyGuard::class)->customerTextSinceLastReply($conversation);
        $new = (bool) preg_match('/(?:عايز|عاوز|محتاج|ممكن|ابعت)[^.؟?\\n]{0,45}(?:اقدم|أقدم|تقديم|طلب)[^.؟?\\n]{0,25}(?:جديد|تاني|تانية|تانيه)|(?:عايز|عاوز|محتاج)[^.؟?\\n]{0,25}(?:اقدم|أقدم)\\s+من\\s+جديد/u', $text);
        $request = app(\App\Domain\Applications\RequestIdentity::class)->query($conversation->customer, $conversation)->latest('id')->first();
        if ($request) return $new;
        // An unverified old request is not a reason to start another application.
        $history = \App\Models\WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('direction', 'incoming')->where('sender_type', 'customer')->latest('id')->limit(20)->get(['text','transcript'])
            ->map(fn ($m) => trim((string) $m->text.' '.(string) $m->transcript))->implode(' ');
        $followup = (bool) preg_match('/(?:طلب|طلبي|طلبى)\\s+(?:ب?رقم|وصل|اتقدم|قدمته)|(?:قدمت|قدّمت)\\s+(?:طلب|قبل|من)/u', $history);
        return $new || ! $followup;
    }

    /**
     * His link (the open one, or a new one with everything he already wrote), filled with what
     * the chat settled so he only writes what is left.
     *
     * @throws FormLinkException
     */
    public function linkForChat(WhatsappConversation $conversation, array $args = []): FormLink
    {
        $work = app(\App\Domain\Applications\WorkProfiles::class)->get($conversation->id) ?? [];

        return $this->links->linkFor($conversation, [
            'customer_name' => $this->realName($args['customer_name'] ?? null),
            // "201200268302:0" - WhatsApp's device suffix is not part of the number
            'phone' => explode(':', (string) ($conversation->real_phone ?: $conversation->phone))[0],
            'applicant_is_other' => ($work['applicant'] ?? 'customer') === 'someone_else',
            'customer_type' => $work['customer_type'] ?? null,
            'work_type' => $work['work_type'] ?? null,
            'workplace_name' => $work['workplace_name'] ?? null,
            'monthly_income' => $work['stated_monthly_income'] ?? null,
            'machine_id' => $args['motorcycle_id'] ?? null,
            'months' => $args['months'] ?? null,
        ]);
    }

    /** Live 2026-10-19: the model passed "العميل" as his name - a word for him is no name. */
    private function realName(?string $name): ?string
    {
        $name = trim((string) $name);
        $generic = ['العميل', 'عميل', 'حضرتك', 'الزبون', 'customer', 'client', 'غير معروف', 'مش معروف', 'unknown'];

        return $name === '' || in_array(mb_strtolower($name), $generic, true) || ! preg_match('/\p{L}{2,}/u', $name) ? null : $name;
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $conversation = WhatsappConversation::find($ctx->conversationId);

        if (! $conversation) {
            return ToolResult::error('NO_CONVERSATION');
        }

        // Existing request facts take priority over application eligibility/link generation.
        if (! $this->maySendLink($conversation)) {
            return ToolResult::ok([
                'has_existing_request' => true,
                'has_open_request' => $this->openRequest($conversation) !== null,
                'requests' => app(\App\Domain\Applications\CustomerRequestStatus::class)->for($conversation->customer, $conversation),
                'link_sent_after_your_reply' => false,
            ]);
        }

        if ($age = app(\App\Domain\Applications\AgeEligibilityGate::class)->for($conversation)) {
            return ToolResult::ok(['age_blocked'=>true,'work_questions_required'=>false,'link_sent_after_your_reply'=>false,'customer_message'=>$age['customer_message']]);
        }

        if (config('agent.safety.enabled')) {
            $classification = app(\App\Domain\Applications\WorkClassification::class);
            $work = $classification->reading($ctx->conversationId);
            if (! $work || empty($work['work_stated'])) {
                return ToolResult::error('WORK_NOT_RECORDED');
            }
            if ($problem = $classification->problem($ctx->conversationId, $work['customer_type'] ?? 'unknown')) {
                return ToolResult::error($problem['code'], $problem['hint']);
            }
            $check = \App\Models\AiTraceStep::where('turn_id', $ctx->turnId)
                ->where('tool_name', 'check_eligibility')->latest('id')->first();
            $result = $check?->result_redacted ?? [];
            if (! ($result['ok'] ?? false)) {
                return ToolResult::error('ELIGIBILITY_CHECK_REQUIRED');
            }
            $data = $result['data'] ?? [];
            if (in_array($data['status'] ?? '', ['not_eligible', 'needs_id'], true) || ! empty($data['occupation_not_accepted']) || ! empty($data['ask_sector'])) {
                return ToolResult::error('ELIGIBILITY_NOT_CONFIRMED');
            }
        }

        if ($open = $this->openRequest($conversation)) {
            return ToolResult::ok([
                'has_open_request' => true,
                'request_id' => $open->id,
                'hint' => 'He already has a request under review: no new link. Tell him its number and that the answer reaches him here.',
            ]);
        }

        try {
            $link = $this->linkForChat($conversation, $args);
        } catch (FormLinkException $e) {
            return ToolResult::error($e->errorCode, 'No application form is published yet: tell him a colleague will send it, and hand off.');
        }

        // marks the link for this turn: the runner sends it after the reply
        $link->update(['prefill' => array_merge((array) $link->prefill, ['_sent_turn_id' => $ctx->turnId])]);
        $progress = $this->links->progress($link);

        $filled = array_values(array_filter(array_map(
            fn ($f) => filled($link->answerValues()[$f['key']] ?? null) ? $f['label'] : null,
            $link->version->formSchema()->fields()
        )));

        return ToolResult::ok([
            'link_sent_after_your_reply' => true,
            'already_filled_for_him' => array_slice($filled, 0, 8),
            'reused_existing_link' => $link->wasRecentlyCreated === false,
            'already_filled_percent' => $progress['percent'],
            'still_missing' => array_slice($progress['missing'], 0, 6),
            'expires_at' => $link->expires_at?->toDateString(),
        ]);
    }
}
