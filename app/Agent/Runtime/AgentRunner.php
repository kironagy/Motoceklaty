<?php

namespace App\Agent\Runtime;

use App\Agent\Context\ContextBuilder;
use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiProviderException;
use App\Agent\Providers\AiRequest;
use App\Agent\Providers\AiResponse;
use App\Agent\Tools\ToolContext;
use App\Agent\Tools\ToolRegistry;
use App\Domain\Handoff\HandoffService;
use App\Exceptions\TransientAiFailure;
use App\Models\AiTrace;
use App\Models\Application;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;

/**
 * T17 §1-3: the agent loop. Turns a claimed turn into a delivered-reply
 * result using the provider, the tool registry, and the deterministic
 * guards on send_reply's output. Every limit comes from config('agent.*')
 * (DEC-23) with no default - run() refuses to start until they're set.
 */
class AgentRunner
{
    public function __construct(
        private readonly AiProvider $ai,
        private readonly ToolRegistry $tools,
        private readonly ContextBuilder $context,
        private readonly HandoffService $handoff,
        private readonly ReplyGuard $guard,
    ) {
    }

    /** @return array{messages: string[], quote_wa_message_id?: ?string, media?: array, focus_motorcycle_ids?: array} */
    public function run(object $turn): array
    {
        $maxModelCalls = config('agent.runtime.max_model_calls');
        $maxToolCalls = config('agent.runtime.max_tool_calls');
        $wallClockSeconds = config('agent.runtime.wall_clock_seconds');

        if ($maxModelCalls === null || $maxToolCalls === null || $wallClockSeconds === null) {
            throw new \RuntimeException('AgentRunner: agent.runtime.* limits are not configured (DEC-23).');
        }

        $conversation = WhatsappConversation::with('customer')->findOrFail($turn->whatsapp_conversation_id);
        $customer = $conversation->customer;
        $activeApplication = $customer
            ? Application::where('customer_id', $customer->id)->whereIn('status', Application::ACTIVE_STATUSES)->latest('id')->first()
            : null;

        $trace = AiTrace::firstOrCreate(
            ['conversation_id' => $conversation->id, 'turn_id' => $turn->id],
            ['status' => 'running']
        );

        // The job row never pointed at its trace, so a failed turn could not
        // be followed from the queue to what the model actually did.
        \Illuminate\Support\Facades\DB::table('whatsapp_message_jobs')
            ->where('id', $turn->id)->whereNull('trace_id')->update(['trace_id' => $trace->id]);

        $request = $this->context->build($turn);
        $contents = $request->contents;
        $toolDeclarations = $this->tools->declarations();

        $ctx = new ToolContext(
            $customer->id, $conversation->id, $activeApplication?->id, $turn->id, $trace->id, new TurnResultBuilder()
        );

        $modelCalls = 0;
        $toolCallCount = 0;
        $numberGuardViolations = 0;
        $guardEvents = [];
        /** @var array<int, array{name: string, ok: bool, data: array}> $outcomes */
        $outcomes = [];
        $forceToolNext = false;
        $started = microtime(true);
        $lastResponse = null;

        try {
            while (true) {
                $limitReached = $modelCalls >= (int) $maxModelCalls
                    || $toolCallCount >= (int) $maxToolCalls
                    || (microtime(true) - $started) >= (int) $wallClockSeconds;

                $response = $this->callModel($request->system, $contents, $toolDeclarations, forceSendReply: $limitReached, forceOtherTool: ! $limitReached && $forceToolNext);
                $forceToolNext = false;
                $lastResponse = $response;
                $this->addUsage($response);
                $modelCalls++;

                $toolCalls = $response->toolCalls;

                if ($toolCalls === []) {
                    // Plain text without send_reply: still a reply candidate,
                    // through the same guards (plan T17 §1.7 - no silent drop).
                    $toolCalls = [['id' => 'implicit', 'name' => 'send_reply', 'args' => ['messages' => [implode(' ', $response->textParts)]]]];
                } else {
                    $contents[] = $this->modelToolCallContent($response);
                }

                $finished = false;

                foreach ($toolCalls as $toolCall) {
                    $toolCallCount++;

                    if ($toolCall['name'] === 'send_reply') {
                        $violation = $this->guard->check($toolCall['args'], $conversation, $request->system, $contents, $outcomes);

                        if ($violation !== null) {
                            $guardEvents[] = ['code' => $violation, 'args' => $toolCall['args']];
                            $numberGuardViolations += in_array($violation, self::NEEDS_TOOL_FIRST, true) ? 1 : 0;

                            // Handing off is the last resort (owner: the bot closes ~90%
                            // itself): two misses used to end the turn with a colleague.
                            if ($numberGuardViolations >= 3 || $this->countCode($guardEvents, 'DUPLICATE_REPLY') >= 3) {
                                return $this->unverifiedReply($conversation, $trace, $guardEvents, $violation);
                            }

                            // Told "call the tool first", the model resent the same
                            // number twice and the turn ended in the "we're busy"
                            // fallback. Its next call has to be a real tool call -
                            // except on the last try, which just has to drop the number.
                            $lastTry = $numberGuardViolations >= 2 || $this->countCode($guardEvents, 'DUPLICATE_REPLY') >= 2;
                            $forceToolNext = ! $lastTry && in_array($violation, self::NEEDS_TOOL_FIRST, true);

                            $contents[] = $this->toolResultContent($toolCall['id'], 'send_reply', ['ok' => false, 'error' => [
                                'code' => $violation,
                                'detail' => (self::GUARD_HINTS[$violation] ?? '').($lastTry
                                    ? ' LAST TRY: send the reply now in different words, leaving out any number you cannot point to in a tool result.'
                                    : ''),
                            ]]);

                            continue;
                        }
                    }

                    $result = $this->tools->execute($toolCall['name'], $toolCall['args'], $ctx);
                    $outcomes[] = [
                        'name' => $toolCall['name'],
                        'ok' => (bool) ($result['ok'] ?? false),
                        'data' => ($result['ok'] ?? false) ? (array) ($result['data'] ?? []) : (array) ($result['error'] ?? []),
                    ];

                    if ($result['ok'] ?? false) {

                        // The context was built before the turn opened an
                        // application; start_application + process_document
                        // in one step lost the customer's ID card to
                        // NO_ACTIVE_APPLICATION.
                        if ($toolCall['name'] === 'start_application' && isset($result['data']['application_id'])) {
                            $ctx = $ctx->withActiveApplication((int) $result['data']['application_id']);
                        }
                    }
                    $contents[] = $this->toolResultContent($toolCall['id'], $toolCall['name'], $result);

                    if ($toolCall['name'] === 'send_reply' && ($result['ok'] ?? false)) {
                        $finished = true;
                    }
                }

                if ($finished) {
                    break;
                }

                if ($limitReached) {
                    return $this->unverifiedReply($conversation, $trace, $guardEvents, 'LIMIT_REACHED_WITHOUT_REPLY');
                }
            }
        } catch (AiProviderException $e) {
            if ($e->retryable) {
                $this->finishTrace($trace, 'error', $guardEvents, $lastResponse, 'PROVIDER_RETRYABLE: '.$e->getMessage());

                throw new TransientAiFailure($e->getMessage(), 0, $e);
            }

            return $this->fallback($conversation, $trace, $guardEvents, 'PROVIDER_FAILURE: '.$e->getMessage());
        } catch (\Throwable $e) {
            // A trace left at 'running' forever hid six crashed turns.
            $this->finishTrace($trace, 'error', $guardEvents, $lastResponse, get_class($e).': '.$e->getMessage());

            throw $e;
        }

        $this->resetFailureCounter($conversation);
        $this->finishTrace($trace, 'done', $guardEvents, $lastResponse);

        return $ctx->outbound->toArray();
    }

    /**
     * A bare code gave the model nothing to act on: after DUPLICATE_REPLY it
     * resent the same sentence and the turn fell through to the "we're busy"
     * fallback on a plain off-topic message. The hint says what to change.
     */
    /**
     * Only a missing source is fixed by forcing a lookup. Forcing a tool
     * after a claim guard made the model call *something* - six motorcycle
     * photos were queued for a customer who had just said goodbye.
     */
    private const NEEDS_TOOL_FIRST = ['UNVERIFIED_NUMBER', 'BRANCH_NOT_SOURCED', 'TOTAL_NOT_SOURCED', 'AGE_NOT_CHECKED'];

    private const GUARD_HINTS = [
        'EMPTY_REPLY' => 'Not sent: the reply is empty. Write the actual message to the customer.',
        'GARBLED_TEXT' => 'Not sent: a word in the reply has Latin letters mixed inside Arabic letters (a typing glitch). Rewrite the reply in clean Egyptian Arabic.',
        'HANDOFF_CLAIMED_NOT_DONE' => 'Not sent: the reply promises a colleague will follow up, but handoff_to_human did not succeed this turn. Call handoff_to_human (reason + short note) first, or do not promise a follow-up.',
        'DATA_CLAIMED_NOT_SAVED' => 'Not sent: the reply says something was saved or changed, but no write tool succeeded this turn. If the customer gave data or a choice, call record_customer_data / update_application_selection first. If nothing needed saving, rewrite the reply without saying anything was saved or changed.',
        'SUBMISSION_CLAIMED_NOT_DONE' => 'Not sent: the reply says the application was sent/submitted, but it was NOT submitted (no successful submit_application with submitted=true). Tell the customer truthfully what is still needed (see the snapshot blockers), or call submit_application if the customer confirmed the reviewed summary.',
        'WORK_TYPE_NOT_RECORDED' => 'Not sent: the reply lists documents that depend on the customer\'s work, but work_type is not saved on the application. If the customer already said what he works (e.g. "شغال اوبر"), call record_customer_data with work_type (quote = his words) first and take the documents from the returned snapshot. If he has not said it, ask what he works instead of listing documents.',
        'UNSOURCED_FINANCE_COMPANY' => 'Not sent: the reply names a finance company that no tool result this turn contains. We only work with the systems get_installment_options returns - call it if the customer asked who finances, otherwise leave company names out.',
        'REPEATED_QUESTION' => 'Not sent: the reply ends with the same question your previous message ended with. The customer did not take it up - answer what he said and ask something else, or ask nothing.',
        'DATA_OVERCLAIMED' => 'Not sent: the reply says his data/details were saved, but this turn saved at most one item. Say exactly what was saved (e.g. "تمام، سجلت شغلك") and ask for application.next_step.',
        'DUPLICATE_REPLY' => 'Not sent: this exact text was already sent to the customer a moment ago. Answer the new message with different wording.',
        'INTERNAL_KEY_IN_REPLY' => 'Not sent: the reply contains an internal key (snake_case like delivery_app). Use the plain Arabic wording instead.',
        'PLACEHOLDER_IN_REPLY' => 'Not sent: the reply contains a history placeholder like [media]. Photos are only sent by calling send_motorcycle_images; write plain text only.',
        'IMAGES_CLAIMED_NOT_SENT' => 'Not sent: the reply says photos are attached but send_motorcycle_images did not succeed this turn. Call it if the customer wants photos, otherwise rewrite without mentioning photos.',
        'TOTAL_NOT_SOURCED' => 'Not sent: the reply states a total that no tool result of this turn contains (a number the customer wrote is not a total). Call get_installment_offer for this motorcycle and duration and quote total_paid, cash_price and installment_price exactly as returned.',
        'UNSOURCED_REASON' => 'Not sent: the reply explains the fees or the installment/cash difference without the owner\'s policy. Call get_installment_offer for this motorcycle and explain the difference only from its price_difference_policy (in your own words) plus its numbers. The admin fees have no recorded reason - just say they are paid once at pickup.',
        'BRANCH_NOT_SOURCED' => 'Not sent: the reply states a branch, an address or opening hours that no get_branch_information result this turn contains. Call get_branch_information (governorate of the customer if he said it) and use only the branches it returns - if his governorate has none, say so and give the nearest ones it returned. Never name a branch or area that is not in the result.',
        'AGE_NOT_CHECKED' => 'Not sent: the reply says his age is fine without a check. Call check_eligibility with his age (and months if a duration is known) and answer from its result - if not_eligible, tell him kindly and clearly.',
        'AVAILABILITY_PROMISE' => 'Not sent: the reply promises to tell him when a model arrives. Nothing records or sends such a notice. Say it is not available with us right now (للأسف مش متوفرة عندنا حاليا), with no date or promise, and offer an available alternative.',
        'DOCUMENT_CLAIMED_NOT_ACCEPTED' => 'Not sent: the reply says his document/photo arrived or was saved, but no process_document accepted a document this turn. If he sent a photo call process_document and answer from its result (a rejected photo: tell him why and ask for a new one). Otherwise do not say it arrived.',
        'WITHDRAWAL_CLAIMED_NOT_DONE' => 'Not sent: the reply says the application was closed/cancelled, but withdraw_application did not succeed this turn. If he clearly asked to cancel, call withdraw_application first; otherwise do not say it was closed.',
        'APPLICATION_CLAIMED_NOT_OPENED' => 'Not sent: the reply says an application was opened, but none is open. Call start_application first (after he said what he works), or do not say it.',
        'UNRECORDED_PROMISE' => 'Not sent: the reply promises something nothing records - a reservation ("محجوزة", "نحجزلك"), a time frame ("نفس اليوم", "خلال كذا يوم"), a warranty, a guarantor, or "I will tell you as soon as...". None of these exist in our data. Remove the promise; say only what the tools returned.',
        'INTEREST_DENIED' => 'Not sent: the reply says there is no interest/no increase. That is false - the installment price is higher than cash. Never deny it. If he asks about interest, give the cash price and what he pays in total from get_installment_offer (the breakdown line), and explain the difference only from price_difference_policy.',
        'SCRIPTED_PHRASE_REQUEST' => 'Not sent: the reply asks the customer to say/write a specific sentence. Never do that. If he already said what he works, use his own earlier words as the quote (record_customer_data / start_application). If he did not, just ask "حضرتك بتشتغل إيه؟".',
        'BANNED_WORDING' => 'Not sent: the reply uses wording the owner banned - a name for yourself (never give yourself a name), "أهلاً بك" or "حقك عليا". Rewrite without it.',
        'UNVERIFIED_NUMBER' => 'Not sent: the reply contains a number (a price, or a measured value like km/litre, hp, months, %) that no tool result or structured state contains. Prices must come from a tool call in THIS turn (the catalog index is for names only) - call get_motorcycle_details / calculate_installment first, or leave the number out. Never state specifications that are not in a tool result.',
    ];

    private function callModel(string $system, array $contents, array $tools, bool $forceSendReply, bool $forceOtherTool = false): AiResponse
    {
        $allowed = match (true) {
            $forceSendReply => ['send_reply'],
            $forceOtherTool => array_values(array_diff(array_column($tools, 'name'), ['send_reply'])),
            default => [],
        };

        return $this->ai->chat(new AiRequest(
            system: $system,
            contents: $contents,
            tools: $tools,
            toolMode: $allowed === [] ? 'auto' : 'any',
            allowedTools: $allowed,
        ));
    }

    private function modelToolCallContent(AiResponse $response): array
    {
        $signatures = $response->rawContinuation['tool_call_signatures'] ?? [];

        return [
            'role' => 'model',
            'parts' => array_map(fn ($tc) => array_filter([
                'type' => 'tool_call',
                'id' => $tc['id'],
                'name' => $tc['name'],
                'args' => $tc['args'],
                'raw' => $signatures[$tc['id']] ?? null,
            ], fn ($v) => $v !== null), $response->toolCalls),
        ];
    }

    private function toolResultContent(string $id, string $name, array $result): array
    {
        return ['role' => 'tool', 'parts' => [['type' => 'tool_result', 'id' => $id, 'name' => $name, 'result' => $result]]];
    }

    private function countCode(array $guardEvents, string $code): int
    {
        return count(array_filter($guardEvents, fn ($e) => $e['code'] === $code));
    }

    /**
     * The model could not produce a reply that passes the guards. Sending
     * the provider-outage notice here told a real customer "we're under
     * pressure" after a plain goodbye. The truthful outcome is that a
     * person will answer: open the handoff and send its waiting message.
     *
     * @return array{messages: string[]}
     */
    private function unverifiedReply(WhatsappConversation $conversation, AiTrace $trace, array $guardEvents, string $reason): array
    {
        $waiting = config('agent.handoff.waiting_message');

        if (blank($waiting)) {
            return $this->fallback($conversation, $trace, $guardEvents, $reason);
        }

        $this->finishTrace($trace, 'fallback', $guardEvents, null, 'GUARD_UNRESOLVED: '.$reason);

        if ($conversation->status !== 'awaiting_agent') {
            $this->handoff->handOff(
                $conversation,
                reason: 'low_confidence',
                note: "The assistant could not produce a verified reply ({$reason}). Please answer the customer's last message.",
                source: 'system',
            );
        }

        return ['messages' => [$waiting]];
    }

    /** @return array{messages: string[]} */
    private function fallback(WhatsappConversation $conversation, AiTrace $trace, array $guardEvents, string $reason): array
    {
        $message = config('agent.fallback.message');

        if ($message === null) {
            throw new \RuntimeException("AgentRunner: fallback triggered ({$reason}) but agent.fallback.message is not configured (DEC-13).");
        }

        $failures = $this->recordFailure($conversation);
        $this->finishTrace($trace, 'fallback', $guardEvents, null, $reason);

        $maxFailedTurns = config('agent.handoff.max_failed_turns');

        if ($maxFailedTurns !== null && $failures >= (int) $maxFailedTurns) {
            $this->handoff->handOffForFailures($conversation, $reason);
        }

        return ['messages' => [$message]];
    }

    /**
     * Consecutive-failed-turns counter (DEC-13), kept in
     * conversation.state so no migration is needed - reset on any turn
     * that ends with an accepted send_reply.
     */
    private function recordFailure(WhatsappConversation $conversation): int
    {
        $state = $conversation->state ?? [];
        $count = ($state['failed_turns_since_success'] ?? 0) + 1;
        $state['failed_turns_since_success'] = $count;
        $conversation->update(['state' => $state]);

        return $count;
    }

    private function resetFailureCounter(WhatsappConversation $conversation): void
    {
        $state = $conversation->state ?? [];

        if (($state['failed_turns_since_success'] ?? 0) !== 0) {
            $state['failed_turns_since_success'] = 0;
            $conversation->update(['state' => $state]);
        }
    }

    private const EMPTY_USAGE = ['model_calls' => 0, 'input_tokens' => 0, 'cached_tokens' => 0, 'output_tokens' => 0, 'thoughts_tokens' => 0];

    private array $usage = self::EMPTY_USAGE;

    private function addUsage(AiResponse $response): void
    {
        $this->usage['model_calls']++;

        foreach (['input_tokens', 'cached_tokens', 'output_tokens', 'thoughts_tokens'] as $key) {
            $this->usage[$key] += (int) ($response->usage[$key] ?? 0);
        }
    }

    private function finishTrace(AiTrace $trace, string $status, array $guardEvents, ?AiResponse $response, ?string $errorCode = null): void
    {
        // Totals for the whole turn: recording only the last model call
        // under-reported a 3-call turn's tokens by about two thirds.
        $responseFields = array_filter([
            'output_tokens' => $this->usage['output_tokens'] ?: ($response?->usage['output_tokens'] ?? null),
            'input_tokens' => $this->usage['input_tokens'] ?: ($response?->usage['input_tokens'] ?? null),
            'model' => $response?->model,
            'latency_ms' => $response?->latencyMs,
        ], fn ($v) => $v !== null);

        $manifest = (array) ($trace->context_manifest ?? []);
        $manifest['usage'] = $this->usage;
        $responseFields['context_manifest'] = $manifest;
        $this->usage = self::EMPTY_USAGE;

        $trace->update($responseFields + [
            'status' => $status,
            'guard_events' => $guardEvents,
            // error_code is a `string` (255) column; a raw provider exception message
            // (e.g. Gemini's full JSON error body) can exceed that and would otherwise
            // throw a DB truncation error, masking the original failure entirely.
            'error_code' => $errorCode !== null ? mb_substr($errorCode, 0, 255) : null,
        ]);
    }
}
