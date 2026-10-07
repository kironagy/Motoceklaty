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
        $purpose = \App\Services\GeminiKeyManager::isTestPhone(WhatsappConversation::whereKey($turn->whatsapp_conversation_id)->value('phone'))
            ? 'simulator' : 'reply';

        if (\App\Services\GeminiKeyManager::purpose() !== $purpose) {
            return \App\Services\GeminiKeyManager::for($purpose, fn () => $this->run($turn));
        }

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

        \App\Agent\Tracing\AiCalls::setTrace($trace->id, 'v1');

        $ctx = new ToolContext(
            $customer->id, $conversation->id, $activeApplication?->id, $turn->id, $trace->id, new TurnResultBuilder()
        );

        // ARCH-006 / ERR-002: what an earlier attempt of this turn already did
        // (read before this attempt runs anything itself).
        $resumed = $this->completedSteps((int) $turn->id);

        // Owner 2026-10-05: a message that is only a greeting gets the
        // showroom's greeting, never the model's improvisation.
        $onlyText = ! \App\Models\WhatsappMessage::where('turn_id', $turn->id)->where('direction', 'incoming')
            ->whereNotIn('type', ['text', 'audio', 'voice', 'ptt'])->exists();

        if ($resumed === [] && $onlyText && blank($turn->media_items ?? null)) {
            $greeting = app(GreetingReply::class)->for($conversation, $this->guard->customerTextSinceLastReply($conversation));

            if ($greeting !== null) {
                $this->resetFailureCounter($conversation);
                $this->finishTrace($trace, 'done', [], null);

                return ['messages' => [$greeting]];
            }
        }

        // read first, so the context shows each photo as its result, not as the image again
        $preread = $activeApplication ? $this->prereadDocuments($turn, $ctx) : null;

        // Rebuild: the model understands and records his facts itself
        // (record_customer_data); no separate understanding call before it.
        $request = $this->context->build($turn);
        $contents = $request->contents;
        $toolDeclarations = $this->toolsFor($activeApplication !== null);

        $modelCalls = 0;
        $toolCallCount = 0;
        $repairs = 0;
        $openedNow = false;
        $guardEvents = [];
        /** @var array<int, array{name: string, ok: bool, data: array}> $outcomes */
        $outcomes = [];
        $started = microtime(true);
        $lastResponse = null;
        /** @var array<string, int> $callsSeen tool+args => times called this turn */
        $callsSeen = [];
        $replyNow = false;

        // QA 2026-10-04 (one question = one call): photos sent to an open
        // application are read before the first call; the model answers
        // from the result in one step.
        if ($resumed !== []) {
            array_push($outcomes, ...array_column($resumed, 'outcome'));
            $contents[] = $this->resumedContent($resumed);
        }

        if ($preread !== null) {
            $outcomes[] = $preread['outcome'];
            $contents[] = $preread['content'];
        }

        try {
            while (true) {
                $limitReached = $modelCalls >= (int) $maxModelCalls - 1
                    || $toolCallCount >= (int) $maxToolCalls
                    || (microtime(true) - $started) >= (int) $wallClockSeconds;

                if ($modelCalls >= (int) $maxModelCalls) {
                    return $this->unverifiedReply($conversation, $trace, $guardEvents, 'LIMIT_REACHED_WITHOUT_REPLY');
                }

                // a step that only repeated a lookup is answered from what it has
                $response = $this->callModel($request->system, $contents, $toolDeclarations, forceSendReply: $limitReached || $replyNow);
                $replyNow = false;
                $lastResponse = $response;
                $this->addUsage($response);
                $modelCalls++;

                $toolCalls = $response->toolCalls;
                // the step's tools run before its reply is judged
                usort($toolCalls, fn ($a, $b) => ($a['name'] === 'send_reply') <=> ($b['name'] === 'send_reply'));

                if ($toolCalls === []) {
                    $toolCalls = [['id' => 'implicit', 'name' => 'send_reply', 'args' => ['messages' => [implode(' ', $response->textParts)]]]];
                } else {
                    $contents[] = $this->modelToolCallContent($response);
                }

                foreach ($toolCalls as $toolCall) {
                    $toolCallCount++;

                    // Owner 2026-10-06 (no loops): the same lookup again, or a
                    // third call of one tool, adds nothing - the next step replies.
                    if ($toolCall['name'] !== 'send_reply' && $this->isLoopingCall($toolCall, $callsSeen)) {
                        $replyNow = true;
                        $guardEvents[] = ['code' => 'LOOP_BROKEN', 'args' => ['tool' => $toolCall['name']]];
                    }

                    if ($toolCall['name'] === 'send_reply') {
                        // Only technical cleanup (emoji, markdown, dashes) - never his words changed.
                        $toolCall['args'] = $this->rewritten($toolCall['args'], $guardEvents, ['tidy' => fn ($a) => $this->guard->tidy($a)]);

                        // PHP checks only what the AI may not decide: claims of
                        // actions, numbers and facts no tool gave, promises.
                        $violation = $this->guard->check($toolCall['args'], $conversation, $request->system, $contents, $outcomes);

                        if ($violation !== null) {
                            $guardEvents[] = ['code' => $violation, 'args' => $toolCall['args']];

                            // One repair, told plainly what is wrong. A second
                            // miss is not argued with: an honest short line.
                            if (($repairs >= 1 || $limitReached) && $ctx->outbound->trailing() !== []) {
                                // Real customers 2026-10-07: at the very last step ("اه" before the summary) two blocked
                                // lines sent a ready customer to a colleague. The summary is already stored and queued;
                                // the one sentence that goes before it is always the same, so code writes it.
                                $this->resetFailureCounter($conversation);
                                $this->finishTrace($trace, 'done', $guardEvents, $lastResponse);

                                return ['messages' => array_merge(['ده ملخص طلبك، راجعه ولو كله تمام قولّي اه.'], $ctx->outbound->trailing())] + $ctx->outbound->toArray();
                            }

                            // Owner 2026-10-07: a complete application is sent, not summarised. The model kept writing
                            // its own summary or asking "نبعته؟" - after one repair, code sends it (the summary only if
                            // he asked to see it).
                            if (($repairs >= 1 || $limitReached) && in_array($violation, ['SUMMARY_CLAIMED_NOT_SENT', 'SUBMIT_NOT_CALLED'], true) && $ctx->activeApplicationId) {
                                $sent = $this->tools->execute('submit_application', ['confirm' => false], $ctx);
                                $reference = $sent['data']['reference']['installment_request_id'] ?? null;

                                if (($sent['ok'] ?? false) && ($sent['data']['submitted'] ?? false) === true) {
                                    $guardEvents[] = ['code' => 'REWRITE:submitted_by_code', 'args' => []];
                                    $this->resetFailureCounter($conversation);
                                    $this->finishTrace($trace, 'done', $guardEvents, $lastResponse);

                                    return ['messages' => ['تمام، طلبك اتبعت للمراجعة'.($reference ? " ورقمه #{$reference}" : '').'، وأي جديد هيوصلك هنا.']] + $ctx->outbound->toArray();
                                }

                                if ($ctx->outbound->trailing() !== []) {
                                    $guardEvents[] = ['code' => 'REWRITE:stored_summary_sent', 'args' => []];
                                    $this->resetFailureCounter($conversation);
                                    $this->finishTrace($trace, 'done', $guardEvents, $lastResponse);

                                    return ['messages' => array_merge(['ده ملخص طلبك، راجعه ولو كله تمام قولّي اه.'], $ctx->outbound->trailing())] + $ctx->outbound->toArray();
                                }
                            }

                            if ($repairs >= 1 || $limitReached) {
                                // Replay of conversation 206 (Gemini): a second repeat ended
                                // in "مش متأكد إني فهمتك" three times - worse than the repeat.
                                // What he already got is dropped and the rest is sent.
                                $trimmed = $violation === 'REPEATED_REPLY' ? $this->guard->withoutRepeatedSentences((array) ($toolCall['args']['messages'] ?? []), $conversation) : null;
                                $step = 'repeated_sentences_dropped';

                                if ($trimmed === null || $this->guard->check(['messages' => $trimmed] + $toolCall['args'], $conversation, $request->system, $contents, $outcomes) !== null) {
                                    // owner 2026-10-07: keep the true sentences, drop the false ones
                                    $trimmed = $this->guard->passingSentences($toolCall['args'], $conversation, $request->system, $contents, $outcomes);
                                    $step = 'failing_sentences_dropped';
                                }

                                if ($trimmed === null) {
                                    return $this->unverifiedReply($conversation, $trace, $guardEvents, $violation);
                                }

                                $toolCall['args']['messages'] = $trimmed;
                                $guardEvents[] = ['code' => 'REWRITE:'.$step, 'args' => $toolCall['args']];
                            } else {
                                $repairs++;
                                $detail = $this->guard->lastDetail();
                                $contents[] = $this->toolResultContent($toolCall['id'], 'send_reply', ['ok' => false, 'error' => [
                                    'code' => $violation,
                                    'detail' => trim((self::GUARD_HINTS[$violation] ?? '').($detail !== null ? ' '.$detail : '')),
                                ]]);

                                continue;
                            }
                        }
                    }

                    // Owner 2026-10-07: "حقك عليا، المفردات اتقبلت" and nothing more - he did not know what came
                    // next. While his application is collecting, a reply that asks nothing says what is next.
                    if ($toolCall['name'] === 'send_reply' && $ctx->activeApplicationId) {
                        $toolCall['args'] = $this->rewritten($toolCall['args'], $guardEvents, ['next_step_added' => fn ($a) => $this->withNextStep($a, $ctx->activeApplicationId)]);
                    }

                    $result = $this->tools->execute($toolCall['name'], $toolCall['args'], $ctx);

                    // What a tool proves (a price looked up = he asked about
                    // that motorcycle) goes to his memory without a model call.
                    if ($toolCall['name'] !== 'send_reply') {
                        app(\App\Domain\Memory\CustomerMemory::class)->recordToolEvent($ctx->customerId, $toolCall['name'], (array) $toolCall['args'], $result);
                        // READ tools write nothing; what they proved is recorded here, once.
                        app(ToolOutcomeRecorder::class)->record($toolCall['name'], (array) $toolCall['args'], $result, $ctx);
                    }

                    $outcomes[] = [
                        'name' => $toolCall['name'],
                        'ok' => (bool) ($result['ok'] ?? false),
                        'data' => ($result['ok'] ?? false) ? (array) ($result['data'] ?? []) : (array) ($result['error'] ?? []),
                    ];

                    // start_application + process_document in one step: the
                    // document needs the application the step just opened.
                    if (($result['ok'] ?? false) && $toolCall['name'] === 'start_application' && isset($result['data']['application_id'])) {
                        $ctx = $ctx->withActiveApplication((int) $result['data']['application_id']);
                        $toolDeclarations = $this->toolsFor(true);
                        $openedNow = (($result['data']['created'] ?? false) === true || isset($result['data']['reopened']));
                    }

                    // the applicant changed and his open application was closed: later tools must not write into it
                    if (($result['ok'] ?? false) && $toolCall['name'] === 'record_work_profile' && isset($result['data']['previous_application_closed'])) {
                        $ctx = $ctx->withoutActiveApplication();
                    }

                    // Only the newest application snapshot is the truth.
                    if (isset($result['data']['snapshot'])) {
                        $contents = $this->withoutOlderSnapshots($contents);
                    }

                    $contents[] = $this->toolResultContent($toolCall['id'], $toolCall['name'], $result);

                    if ($toolCall['name'] === 'send_reply' && ($result['ok'] ?? false)) {
                        $this->resetFailureCounter($conversation);
                        $this->finishTrace($trace, 'done', $guardEvents, $lastResponse);

                        return $ctx->outbound->toArray();
                    }
                }

                // DOC-006: papers he sent before there was an application are read
                // now - after the step's tool results (a provider wants those together)
                if ($openedNow && ($earlier = $this->readEarlierDocuments($ctx)) !== null) {
                    $outcomes[] = $earlier['outcome'];
                    $contents[] = $earlier['content'];
                }

                $openedNow = false;
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

    }



    /**
     * Rebuild: one short factual line per refusal - what is untrue and what
     * makes it true. No wording rules: tone and phrasing are the
     * instructions' job, never a refusal.
     */
    public const GUARD_HINTS = [
        'EMPTY_REPLY' => 'The reply is empty.',
        'GARBLED_TEXT' => 'A word mixes Latin letters into Arabic (a typing glitch).',
        'INTERNAL_KEY_IN_REPLY' => 'The reply shows an internal key or code name.',
        'PLACEHOLDER_IN_REPLY' => 'The reply contains a placeholder in brackets, not real text.',
        'SUBMISSION_CLAIMED_NOT_DONE' => 'It says the application was sent, but submit_application did not succeed.',
        'SUBMISSION_DENIED_BUT_DONE' => 'submit_application succeeded this turn: the request WAS sent - give him its number from reference.',
        'RESUBMISSION_CLAIMED' => 'It says the request went again or elsewhere; nothing was submitted this turn.',
        'RESUBMISSION_PROMISED' => 'Moving a submitted request to another finance company is staff-only.',
        'DATA_CLAIMED_NOT_SAVED' => 'It says something was saved or changed, but no write tool succeeded. If he sent data this turn, call record_customer_data with it now, then reply.',
        'SUMMARY_CLAIMED_NOT_SENT' => 'It talks about his summary, but only submit_application (confirm=true) sends it - call it.',
        'DATA_OVERCLAIMED' => 'It says all his data was saved; only part was.',
        'DOCUMENT_CLAIMED_NOT_ACCEPTED' => 'It says a document was received/accepted; process_document did not accept it.',
        'DOCUMENT_PHOTO_NOT_PROCESSED' => 'He sent a photo this turn that was not read; process_document (or identify_motorcycle_from_image) first.',
        'WITHDRAWAL_CLAIMED_NOT_DONE' => 'It says the application was cancelled; withdraw_application did not succeed.',
        'APPLICATION_CLAIMED_NOT_OPENED' => 'It says an application was opened; start_application did not succeed.',
        'APPLICATION_ALREADY_OPEN_CLAIMED' => 'His application was already open before this turn; it was not opened now.',
        'SELECTION_CLAIMED_NOT_SET' => 'The motorcycle/plan it states is not what the application holds.',
        'COMPLETION_OVERCLAIMED' => 'It says the data is complete; items are still missing.',
        'IMAGES_CLAIMED_NOT_SENT' => 'It says photos were sent; send_motorcycle_images did not succeed.',
        'IMAGES_CLAIMED_FOR_UNSENT_MODEL' => 'It names photos of a model whose photos were not sent.',
        'HANDOFF_CLAIMED_NOT_DONE' => 'It promises a colleague; handoff_to_human did not succeed.',
        'HANDOFF_TIME_PROMISED' => 'It promises when a colleague will answer; nothing records that.',
        'UNRECORDED_PROMISE' => 'It promises something nothing in the system does (reservation, time, warranty).',
        'STOCK_OR_CHECK_CLAIMED' => 'Nothing checks stock per branch or follows up later.',
        'AVAILABILITY_PROMISE' => 'Nothing notifies him when a model arrives.',
        'UNVERIFIED_NUMBER' => 'A number in the reply is in no tool result or earlier verified data; look it up or leave it out.',
        'TOTAL_NOT_SOURCED' => 'The total it states is not in a tool result.',
        'PERCENT_DURATION_MISMATCH' => 'That rate does not belong to that duration in the active plans.',
        'FORMAL_ARABIC' => 'Part of the reply is in فصحى; write it in plain Egyptian Arabic as he writes.',
        'JOB_AS_TITLE' => 'Do not address him by his job; no title, or "يا باشا" (يا فندم for a woman) once in a while.',
        'KIND_NOT_ASKED' => 'It offers a vehicle of a kind he did not ask for.',
        'ACCEPTED_DOCUMENT_ASKED_AGAIN' => 'It asks for a paper that is already accepted.',
        'BUSINESS_NAME_UNCONFIRMED' => 'Ask him about the business name on his tax card.',
        'DATA_GIVEN_NOT_RECORDED' => 'He gave data in this message that is not recorded yet.',
        'FIRST_PAYMENT_NOT_SOURCED' => 'The first installment\'s timing is a setting, not a guess.',
        'SUBMIT_NOT_CALLED' => 'Nothing is missing on his application: call submit_application now (it sends it); do not ask him to confirm or summarise it yourself.',
        'UNLISTED_DOCUMENT' => 'It asks for a paper no requirement list holds.',
        'DOCUMENTS_ASKED_WITHOUT_APPLICATION' => 'It asks him to send his papers but no application is open. He wants to apply: call start_application first (it asks what it still needs), then reply.',
        'REAPPLY_OFFERED_AFTER_SUBMIT' => 'His request is already sent (see requests). Answer his question from it; do not offer to apply again or start a new request.',
        'BRANCH_NOT_SOURCED' => 'Branch facts come from get_branch_information only.',
        'MODEL_NOT_LOOKED_UP' => 'It names a motorcycle no tool returned this turn.',
        'UNSOURCED_FINANCE_COMPANY' => 'It names a finance company we do not work with.',
        'AGE_NOT_CHECKED' => 'Whether his age qualifies comes from check_eligibility only.',
        'WORK_REFUSAL_NOT_SOURCED' => 'A refusal of his work comes from check_eligibility / start_application only.',
        'UNSOURCED_SALES_CLAIM' => 'It praises a motorcycle with a claim no tool returned (best seller, saves fuel, parts always available, tough).',
        'MASCULINE_ADDRESS_TO_WOMAN' => 'She is recorded as a woman: speak to her in the feminine and drop "يا باشا / يا غالي / يا معلم" - use "يا فندم" or no title.',
        'REQUIRED_DOCUMENT_WAIVED' => 'Every document on his list is required; it cannot be waived.',
        'INTEREST_DENIED' => 'Installments do carry interest; it cannot be denied.',
        'REPEATED_REPLY' => 'He already got these exact words from you. Answer his new message itself in one short line in other words; a refusal he already heard = one short line ("زي ما قلتلك، للأسف مش هينفع وهو مش شغال") with no list of options again.',
        'PERSON_NOT_RECORDED' => 'His relatives are "أخوك/أبوك/والدتك" when you speak to him - never "أخويا/ابويا/امي". The reply speaks of one person (أخوك/أبوك...) as the one applying, but that is not who is recorded. If his own words clearly name ONE person, call record_work_profile (applicant=someone_else, applicant_relation, applicant_quote = his words) first; if they do not ("اخ اخويا حبيب صاحب", or "اه" to your own guess), reply only: مين اللي هيقدّم؟',
    ];

    /**
     * The tools an open application adds, in this order, after every other tool.
     */
    public const APPLICATION_TOOLS = ['update_application_selection', 'submit_application', 'withdraw_application'];

    /** Photo tools: in both sets (a photo can come at any stage), right before the application tools. */
    public const PHOTO_TOOLS = ['identify_motorcycle_from_image', 'process_document'];

    /**
     * TOOL-013: two stable sets - browsing and open application. Four
     * variants (application x photo in this turn) changed the tool list
     * from turn to turn, and the provider cache (it caches the request from
     * its start, tools first) missed whenever a photo came or went. Now the
     * browsing set is an exact prefix of the application set: stable tools,
     * then the photo tools, then the application tools. Rule documented in
     * docs/rebuild/tool-contract-v2.md.
     */
    private function toolsFor(bool $hasApplication): array
    {
        $tail = array_merge(self::PHOTO_TOOLS, $hasApplication ? self::APPLICATION_TOOLS : []);
        $all = $this->tools->declarations();
        $stable = array_values(array_filter($all, fn ($d) => ! in_array($d['name'], [...self::PHOTO_TOOLS, ...self::APPLICATION_TOOLS], true)));
        $byName = array_column($all, null, 'name');

        return array_merge($stable, array_values(array_filter(array_map(fn ($name) => $byName[$name] ?? null, $tail))));
    }

    /**
     * The same tool with the same arguments again this turn, or a lookup
     * (not a write) called for the third time: the model is going round
     * instead of answering.
     *
     * @param  array<string, int>  $callsSeen
     */
    private function isLoopingCall(array $toolCall, array &$callsSeen): bool
    {
        $args = (array) ($toolCall['args'] ?? []);
        ksort($args);
        $key = $toolCall['name'].':'.json_encode($args, JSON_UNESCAPED_UNICODE);
        $byName = 'name:'.$toolCall['name'];

        $repeated = isset($callsSeen[$key]);
        $callsSeen[$key] = ($callsSeen[$key] ?? 0) + 1;
        $callsSeen[$byName] = ($callsSeen[$byName] ?? 0) + 1;

        return $repeated || (! in_array($toolCall['name'], ReplyGuard::WRITE_TOOLS, true) && $callsSeen[$byName] >= 3);
    }

    /**
     * Applies the silent rewrites in order; every one that changed the reply
     * leaves a REWRITE:<step> event with what it was and what it became.
     *
     * @param  array<string, callable(array): array>  $steps
     */
    private function rewritten(array $args, array &$guardEvents, array $steps): array
    {
        foreach ($steps as $step => $rewrite) {
            $after = $rewrite($args);

            if ($after !== $args) {
                $guardEvents[] = ['code' => 'REWRITE:'.$step, 'args' => [
                    'before' => $args['messages'] ?? $args,
                    'after' => $after['messages'] ?? $after,
                ]];
            }

            $args = $after;
        }

        return $args;
    }

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
            label: 'main',
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

    /**
     * This turn's photos that no tool read yet, read now through the same
     * tool (traced, guarded). A photo that is not a document (a motorcycle,
     * a selfie) is left for the model - nothing is recorded for it.
     *
     * @return array{outcome: array, content: array}|null
     */
    private function prereadDocuments(object $turn, ToolContext $ctx): ?array
    {
        $mediaIds = \App\Models\MessageMedia::query()
            ->whereHas('message', fn ($q) => $q->where('whatsapp_conversation_id', $ctx->conversationId)
                ->where('direction', 'incoming')->where('turn_id', $turn->id))
            ->whereIn('media_type', ['image', 'document'])
            ->whereNotIn('id', \App\Models\ApplicationDocument::where('application_id', $ctx->activeApplicationId)->whereNotNull('media_id')->select('media_id'))
            ->get()
            ->reject(fn ($m) => isset(($m->analysis ?? [])['band']) || isset(($m->analysis ?? [])['not_a_document']))
            ->pluck('id')
            ->take(4)
            ->all();

        if ($mediaIds === []) {
            return null;
        }

        $result = $this->tools->execute('process_document', ['media_ids' => $mediaIds, '_preread' => true], $ctx);

        return [
            'outcome' => [
                'name' => 'process_document',
                'ok' => (bool) ($result['ok'] ?? false),
                'data' => ($result['ok'] ?? false) ? (array) ($result['data'] ?? []) : (array) ($result['error'] ?? []),
            ],
            'content' => ['role' => 'user', 'parts' => [['type' => 'text', 'text' => "## الصور اللي بعتها في الرسالة دي اتقرت خلاص (process_document - ما تناديهاش تاني عليها)\n```json\n"
                .json_encode($result, JSON_UNESCAPED_UNICODE)."\n```\nرد عليه من النتيجة دي."]]],
        ];
    }

    /**
     * DOC-006: an ID photo sent before he said "عايز اقدم" waited until the
     * model thought of it (often never - he was asked to send it again).
     * Right after start_application opens his application, his last week's
     * unread document photos (this turn's too) go through process_document.
     */
    private function readEarlierDocuments(ToolContext $ctx): ?array
    {
        $mediaIds = \App\Models\MessageMedia::query()
            ->whereHas('message', fn ($q) => $q->where('whatsapp_conversation_id', $ctx->conversationId)
                ->where('direction', 'incoming')->where('created_at', '>=', now()->subDays(7)))
            ->whereIn('media_type', ['image', 'document'])
            ->whereNotIn('id', \App\Models\ApplicationDocument::whereNotNull('media_id')->select('media_id'))
            ->latest('id')
            ->get()
            ->reject(fn ($m) => isset(($m->analysis ?? [])['band']) || isset(($m->analysis ?? [])['not_a_document']))
            ->pluck('id')
            ->take(4)
            ->all();

        if ($mediaIds === []) {
            return null;
        }

        $result = $this->tools->execute('process_document', ['media_ids' => $mediaIds, '_preread' => true], $ctx);

        return [
            'outcome' => [
                'name' => 'process_document',
                'ok' => (bool) ($result['ok'] ?? false),
                'data' => ($result['ok'] ?? false) ? (array) ($result['data'] ?? []) : (array) ($result['error'] ?? []),
            ],
            'content' => ['role' => 'user', 'parts' => [['type' => 'text', 'text' => "## الصور اللي بعتها قبل ما الطلب يتفتح اتقرت دلوقتي (process_document - ما تناديهاش تاني عليها)\n```json\n"
                .json_encode($result, JSON_UNESCAPED_UNICODE)."\n```"]]],
        ];
    }

    /**
     * ARCH-006 / ERR-002: a turn re-queued after a provider failure ran every
     * model and tool call again from the start - a "قدم الطلب" turn that died
     * after start_application could open the request twice. The tool steps
     * the earlier attempt completed (ai_trace_steps) are handed to the model
     * as done; the registry also returns the stored result for the same call.
     *
     * @return list<array{name: string, args: array, result: array, outcome: array}>
     */
    private function completedSteps(int $turnId): array
    {
        return \App\Models\AiTraceStep::where('turn_id', $turnId)->where('kind', 'tool_call')
            ->where('tool_name', '!=', 'send_reply')->orderBy('id')->get()
            ->map(function (\App\Models\AiTraceStep $step) {
                $result = (array) $step->result_redacted;

                // the application in the context is newer than any snapshot then
                if (isset($result['data']['snapshot'])) {
                    $result['data']['snapshot'] = 'superseded - the application in the context is current';
                }

                return [
                    'name' => $step->tool_name,
                    'args' => array_diff_key((array) $step->args_redacted, ['_preread' => 1]),
                    'result' => $result,
                    'outcome' => [
                        'name' => $step->tool_name,
                        'ok' => (bool) ($result['ok'] ?? false),
                        'data' => ($result['ok'] ?? false) ? (array) ($result['data'] ?? []) : (array) ($result['error'] ?? []),
                    ],
                ];
            })->values()->all();
    }

    /** One plain block, the same for every provider (no synthetic tool-call ids or signatures). */
    private function resumedContent(array $steps): array
    {
        $done = array_map(fn ($s) => ['tool' => $s['name'], 'args' => $s['args'], 'result' => $s['result']], $steps);

        return ['role' => 'user', 'parts' => [['type' => 'text', 'text' => "## اتعمل خلاص في نفس الرسالة دي قبل عطل مؤقت (ما تعيدش أي خطوة منهم - كمّل من بعدهم ورد عليه)\n```json\n"
            .json_encode($done, JSON_UNESCAPED_UNICODE)."\n```"]]];
    }

    private function withoutOlderSnapshots(array $contents): array
    {
        foreach ($contents as $i => $content) {
            if (($content['role'] ?? null) !== 'tool') {
                continue;
            }

            foreach ($content['parts'] as $j => $part) {
                if (isset($part['result']['data']['snapshot'])) {
                    $contents[$i]['parts'][$j]['result']['data']['snapshot'] = 'superseded - the newest tool result has the current snapshot';
                }
            }
        }

        return $contents;
    }

    private function toolResultContent(string $id, string $name, array $result): array
    {
        return ['role' => 'tool', 'parts' => [['type' => 'tool_result', 'id' => $id, 'name' => $name, 'result' => $result]]];
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

        // Owner 2026-10-02: one reply the guards would not pass sent the
        // customer to a colleague and the bot went quiet for twenty minutes
        // (18 times in a week). The first time it keeps the conversation
        // going with something true; only a second miss in a row hands off.
        // A customer who cannot bring a paper is not a failed turn: the guard's repair told the
        // model the legal way out; a colleague is no better placed to waive it (real customers 2026-10-07).
        // Owner 2026-10-07: a colleague only on a real trigger - three unresolved turns in a row, not two.
        if ($this->recordFailure($conversation) < 3 || $reason === 'REQUIRED_DOCUMENT_WAIVED') {
            $this->finishTrace($trace, 'fallback', $guardEvents, null, 'GUARD_UNRESOLVED_KEPT_GOING: '.$reason);

            return ['messages' => [$this->keepGoingReply($conversation)]];
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

    /**
     * Owner 2026-10-06: the same canned line twice in a row is what makes him
     * feel he is talking to a machine - the one he got last is skipped.
     */
    private function keepGoingReply(WhatsappConversation $conversation): string
    {
        $last = trim((string) WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('direction', 'outgoing')->where('sender_type', 'bot')->latest('id')->value('text'));

        foreach ($this->keepGoingLines($conversation) as $line) {
            if ($line !== $last) {
                return $line;
            }
        }

        return 'قولّي تحب نكمّل في إيه؟';
    }

    /** @return string[] replies that claim nothing: the next thing his application needs, or a question */
    private function keepGoingLines(WhatsappConversation $conversation): array
    {
        $application = $conversation->customer_id
            ? Application::where('customer_id', $conversation->customer_id)->whereIn('status', Application::ACTIVE_STATUSES)->latest('id')->first()
            : null;

        try {
            $step = $application ? (app(\App\Domain\Applications\SnapshotService::class)->for($application)['next_step'] ?? null) : null;
        } catch (\Throwable) {
            $step = null;
        }

        // Owner 2026-10-07: a sent request still had next_step "submit" and he was
        // asked "أقدّمهولك دلوقتي؟" about the request he had just sent.
        if ($application && $application->status !== 'collecting') {
            $number = \App\Models\InstallmentRequest::where('application_id', $application->id)->value('id');
            $ref = $number ? " رقم #{$number}" : '';

            return ["طلبك{$ref} اتبعت وبيتراجع، وأي جديد هيوصلك هنا. تحب تسأل عن حاجة فيه؟", "طلبك{$ref} عند المراجعة دلوقتي. قولّي عايز تعرف إيه وأنا معاك."];
        }

        // Simulator 2026-10-05: a 64-year-old already told installments are not
        // possible got "نكمّل طلبك؟" from this line.
        if (($step['type'] ?? null) === 'not_eligible') {
            return ['معاك. لو حابب تشتري كاش من الفرع أو حد تاني شغال يقدّم باسمه قولّي.', 'الكاش متاح في الفرع في أي وقت، ولو حد تاني شغال يحب يقدّم باسمه قولّي.'];
        }

        if (($step['type'] ?? null) === 'document' && filled($step['label'] ?? null) && $application) {
            $rule = app(\App\Domain\Applications\SnapshotService::class)->for($application)['documents']['if_unavailable'] ?? null;

            // He cannot bring it: ask the one question that decides the route, instead of the same demand again
            if (($rule['rule'] ?? null) === 'card_only_last_resort') {
                return ["لو مش هتقدر تجيب {$step['label']} خالص قولّي بوضوح وأكمّل معاك بالبطاقة بس.", "تقدر تجيب {$step['label']}؟ لو لأ قولّي ونكمّل بالبطاقة بس."];
            }

            if (($rule['rule'] ?? null) === 'insurance_print_then_card_only') {
                return ['لو الشركة مش بتطلع مفردات، ابعتلي برنت التأمينات بداله. ولو مش هتقدر تجيب ولا واحد منهم قولّي ونكمّل بالبطاقة بس.'];
            }
        }

        if (in_array($step['type'] ?? null, ['field', 'document'], true) && filled($step['label'] ?? null)) {
            return ["تمام، ابعتلي {$step['label']}", "فاضل {$step['label']} ونكمّل."];
        }

        // Conversation 857: "خلاص خلينا في الهوجن L250 على سنتين" with
        // everything in got "ممكن توضحلي تقصد إيه؟" - he was perfectly clear.
        if (($step['type'] ?? null) === 'submit') {
            return ['كده طلبك جاهز. أقدّمهولك دلوقتي؟', 'كله تمام، أقدّم الطلب؟'];
        }

        if ($application) {
            return ['معاك. نكمّل طلبك؟', 'تمام، نكمّل في الطلب؟'];
        }

        // No application yet: if he was quoted something that is still true, continue from it
        // instead of asking him to explain himself again (real customers 2026-10-07)
        $quote = \App\Domain\Conversations\QuotedOffer::classify($conversation)['valid'][0] ?? null;

        if ($quote !== null) {
            return ["تحب نكمّل على {$quote['motorcycle']} على {$quote['months']} شهر ونبدأ الطلب؟", "نبدأ طلب التقسيط على {$quote['motorcycle']}؟"];
        }

        return ['معلش وضّحلي قصدك أكتر؟', 'ممكن تقولّي تاني إنت محتاج إيه بالظبط؟'];
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

        // ContextBuilder wrote the manifest through another model instance:
        // read it back, or the layer sizes are overwritten with usage only
        // (every server trace had lost them).
        $manifest = (array) ($trace->fresh()?->context_manifest ?? $trace->context_manifest ?? []);
        $manifest['usage'] = $this->usage;
        $manifest['ai_calls'] = \App\Agent\Tracing\AiCalls::summaryForTrace($trace->id);
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

    /** The reply plus "فاضل X" when it asks him nothing while his application still needs something. */
    private function withNextStep(array $args, int $applicationId): array
    {
        $messages = array_values((array) ($args['messages'] ?? []));
        $text = implode(' ', $messages);
        $application = Application::find($applicationId);

        // a question, or a request for something ("ابعتلي", "محتاج", "قولّي"), already says what is next
        if ($messages === [] || ! $application || $application->status !== 'collecting'
            || preg_match('/[؟?]|ابعت|تبعت|محتاج|قول(?:ّ)?ي|قوللي|اكتب(?:لي)?|فاضل|ناقص/u', $text)) {
            return $args;
        }

        $step = app(\App\Domain\Applications\SnapshotService::class)->for($application)['next_step'] ?? [];
        $label = trim((string) ($step['label'] ?? ''));

        if (! in_array($step['type'] ?? null, ['field', 'document'], true) || $label === '' || str_contains($text, $label)) {
            return $args;
        }

        $messages[count($messages) - 1] = rtrim($messages[count($messages) - 1]).($step['type'] === 'document' ? "\n\nفاضل تبعتلي {$label}." : "\n\nفاضل {$label}.");

        return ['messages' => $messages] + $args;
    }
}
