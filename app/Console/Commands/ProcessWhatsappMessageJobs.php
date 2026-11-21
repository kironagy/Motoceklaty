<?php

namespace App\Console\Commands;

use App\Agent\Runtime\TurnProcessor;
use App\Domain\Conversations\DeliveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessWhatsappMessageJobs extends Command
{
    // Owner 2026-11-22: one worker per active bot (--bot), started by whatsapp:supervise-jobs, so a
    // busy number never holds up another number's customers. Without --bot it is the old shared pool.
    protected $signature = 'whatsapp:process-jobs {--sleep=1} {--workers=3} {--bot= : only this bot\'s jobs} {--parent= : exit when this supervisor pid is gone}';
    protected $description = 'Process queued WhatsApp AI message jobs';

    /** @var resource|null kept open for the process lifetime so flock() stays held */
    private $lockHandle = null;

    /** 1-based slot this process holds, for log lines. */
    private int $slot = 0;

    /** the only bot this worker answers for, null = every bot (shared pool) */
    private ?int $botId = null;

    private int $botCheckedAt = 0;

    public function handle(): int
    {
        $this->botId = filled($this->option('bot')) ? (int) $this->option('bot') : null;
        $workers = $this->botId ? 1 : max(1, (int) $this->option('workers'));

        if (! $this->acquireWorkerSlot($workers)) {
            $this->error("All {$workers} whatsapp:process-jobs slots are already taken. Exiting.");
            Log::warning('whatsapp:process-jobs refused to start: no free slot', [
                'workers' => $workers,
            ]);

            return self::FAILURE;
        }

        $this->info($this->botId ? "WhatsApp queue worker started for bot {$this->botId}" : "WhatsApp queue worker started (slot {$this->slot}/{$workers})");

        while (true) {
            if (! $this->shouldKeepRunning()) {
                $this->info("WhatsApp queue worker for bot {$this->botId} stopped (bot off, deleted or supervisor gone)");

                return self::SUCCESS;
            }

            // dashboard settings (bot on/off, model, limits) take effect
            // without restarting this long-running worker
            \App\Domain\Settings\AgentSettings::apply();

            if (! config('agent.enabled')) {
                sleep((int) $this->option('sleep'));
                continue;
            }

            // Owner 2026-11-22: with a worker per bot, 11 workers at once hit the AI key's limit
            // ("usage limit reached") and replies failed. Only agent.max_parallel_turns turns
            // generate at once; a bot waits for a free one, then the reply is sent outside it.
            $this->releaseTurnSlot();
            if ($this->botId && $this->anythingClaimable() && ! $this->acquireTurnSlot()) {
                usleep(random_int(300, 1000) * 1000);
                continue;
            }

            $job = $this->claimNextJob();

            if (!$job) {
                $this->releaseTurnSlot();
                sleep((int) $this->option('sleep'));
                continue;
            }

            if ($job->status !== 'generated' && ($this->supersedeIfStale($job) || $this->deferForTranscription($job))) {
                continue;
            }

            $wasAlreadyGenerated = $job->status === 'generated';
            $generationSucceeded = false;

            try {
                if ($wasAlreadyGenerated) {
                    /*
                     * Generation (reply text, state mutation, OCR,
                     * InstallmentRequest creation, ...) already ran and
                     * succeeded on a previous attempt - only delivery
                     * failed. Re-running processQueuedWhatsappJob() here
                     * would duplicate all of that (see
                     * AI_WHATSAPP_BOT_MEMORY_INTELLIGENCE_AUDIT.md §16.1).
                     * Resend the stored result instead.
                     */
                    $result = $this->decodeJobResult($job);
                    $generationSucceeded = true;

                    $this->line(sprintf(
                        '[%s] Resending stored result for job #%d (previous delivery failed)',
                        now()->toDateTimeString(),
                        $job->id
                    ));
                } else {
                    $this->line(sprintf(
                        '[%s] Processing job #%d from %s: %s',
                        now()->toDateTimeString(),
                        $job->id,
                        $job->reply_jid ?: $job->from,
                        $job->message ?: '[media]'
                    ));

                    $result = app(TurnProcessor::class)->process($job);

                    if (!is_array($result)) {
                        $result = [];
                    }

                    $this->line(sprintf(
                        '[%s] AI result for job #%d: messages=%d media=%d',
                        now()->toDateTimeString(),
                        $job->id,
                        count($result['messages'] ?? []),
                        count($result['media'] ?? [])
                    ));

                    /*
                     * Persist BEFORE attempting delivery. If deliver()
                     * throws below, the catch block leaves this job at
                     * status='generated' (not 'pending') so a retry skips
                     * straight to resending this exact result.
                     */
                    /*
                     * The linearization point for DEC-07: new messages that
                     * arrived while this turn was generating flipped it to
                     * 'superseded', and an unconditional write here used to
                     * flip it straight back - the stale reply (a 12-month
                     * quote after the customer had asked for 18) went out.
                     * Only a turn still 'processing' may become 'generated';
                     * a superseded one keeps its result for observability
                     * and is never delivered.
                     */
                    $claimedForDelivery = DB::table('whatsapp_message_jobs')
                        ->where('id', $job->id)
                        ->where('status', 'processing')
                        ->whereNull('superseded_by')
                        ->update([
                            'status' => 'generated',
                            'result' => json_encode($result, JSON_UNESCAPED_UNICODE),
                            'updated_at' => now(),
                        ]);

                    if ($claimedForDelivery === 0) {
                        DB::table('whatsapp_message_jobs')->where('id', $job->id)->update([
                            'status' => 'superseded',
                            'result' => json_encode($result, JSON_UNESCAPED_UNICODE),
                            'locked_at' => null,
                            'processed_at' => now(),
                            'updated_at' => now(),
                        ]);

                        $this->line(sprintf('[%s] Job #%d superseded while generating - reply not delivered', now()->toDateTimeString(), $job->id));

                        continue;
                    }

                    $generationSucceeded = true;
                }

                // the send gap (30-45 s per number) does not hold an AI turn
                $this->releaseTurnSlot();
                app(DeliveryService::class)->deliver($job, $result);

                DB::table('whatsapp_message_jobs')
                    ->where('id', $job->id)
                    ->whereIn('status', ['processing', 'generated'])
                    ->update([
                        'status' => 'done',
                        'processed_at' => now(),
                        'locked_at' => null,
                        'error' => null,
                        'updated_at' => now(),
                    ]);

                $this->info("Done job #{$job->id}");
            } catch (\Throwable $e) {
                Log::error('WHATSAPP JOB FAILED', [
                    'job_id' => $job->id,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                $this->error(sprintf(
                    "[%s] Job #%d failed: %s\n%s",
                    now()->toDateTimeString(),
                    $job->id,
                    $e->getMessage(),
                    $e->getTraceAsString()
                ));

                /*
                 * If generation already succeeded (this failure is purely a
                 * delivery/network problem), fall back to 'generated' - not
                 * 'pending' - so the retry resends the same stored result
                 * instead of running the whole pipeline again.
                 */
                $failureStatus = ((int) $job->attempts >= (int) config('agent.delivery.max_attempts'))
                    ? 'failed'
                    : ($generationSucceeded ? 'generated' : 'pending');

                $processAfter = null;

                if (! $generationSucceeded && $e instanceof \App\Exceptions\TransientAiFailure) {
                    [$failureStatus, $processAfter] = $this->scheduleTransientRetry($job);
                }

                // A delivery failure (WhatsApp disconnected) was retried three
                // times inside ten seconds and gave up; back off instead, and
                // make a final give-up loud.
                if ($generationSucceeded && $failureStatus === 'generated') {
                    $processAfter = now()->addSeconds(15 * max(1, (int) $job->attempts));
                }

                if ($failureStatus === 'failed') {
                    Log::error('WhatsApp turn permanently failed', [
                        'job_id' => $job->id,
                        'conversation_id' => $job->whatsapp_conversation_id,
                        'stage' => $generationSucceeded ? 'delivery' : 'generation',
                        'error' => mb_substr($e->getMessage(), 0, 300),
                    ]);
                }

                DB::table('whatsapp_message_jobs')
                    ->where('id', $job->id)
                    ->update([
                        'status' => $failureStatus,
                        'locked_at' => null,
                        'error' => $e->getMessage(),
                        'updated_at' => now(),
                    ] + ($processAfter ? ['process_after' => $processAfter] : []));

                /*
                 * A transient AI failure (rate limit, a key mid-cooldown,
                 * a flaky call) is retried by design - but re-claiming
                 * the same job a second later usually walks straight back
                 * into whatever caused it, which burns the one retry the
                 * customer has before the turn goes to a human. Give the
                 * provider a few seconds to come back first.
                 */
                sleep(1);
            }
        }
    }

    /**
     * A turn retrying after an AI outage must not answer after a newer turn
     * of the same conversation: "شغال نجار" came back minutes later, after
     * the customer had already switched to cash and been answered. Its
     * messages are already in the newer turn's history.
     */
    private function supersedeIfStale(object $job): bool
    {
        if ((int) $job->attempts <= 1 || ! $job->whatsapp_conversation_id) {
            return false;
        }

        // Only a real customer turn counts - the busy notice and staff
        // replies also create job rows, and treating the notice as "newer"
        // superseded the very turn it was apologising for.
        $newer = DB::table('whatsapp_message_jobs')
            ->where('whatsapp_conversation_id', $job->whatsapp_conversation_id)
            ->where('id', '>', $job->id)
            ->whereNotIn('status', ['superseded'])
            ->whereExists(fn ($q) => $q->from('whatsapp_messages')
                ->whereColumn('whatsapp_messages.turn_id', 'whatsapp_message_jobs.id')
                ->where('whatsapp_messages.direction', 'incoming'))
            ->min('id');

        if ($newer === null) {
            return false;
        }

        DB::table('whatsapp_message_jobs')->where('id', $job->id)->update([
            'status' => 'superseded',
            'superseded_by' => $newer,
            'locked_at' => null,
            'updated_at' => now(),
        ]);

        // His messages from the outage were never answered: they go to the
        // newer turn as part of what it answers, not as old history.
        \App\Models\WhatsappMessage::where('turn_id', $job->id)->where('direction', 'incoming')
            ->update(['turn_id' => $newer]);

        return true;
    }

    /**
     * DEC-08's transcript wait was configured but never applied: a voice
     * note's turn ran before its transcription finished, the model saw no
     * message at all and sent an empty reply - the customer's details,
     * spoken in the voice note, got no answer. Put the turn back for a
     * couple of seconds while a transcription in this turn is still
     * pending, up to agent.turns.transcript_wait_seconds.
     */
    private function deferForTranscription(object $job): bool
    {
        $pending = \App\Models\WhatsappMessage::where('turn_id', $job->id)
            ->where('transcription_status', 'pending')
            ->min('created_at');

        if ($pending === null) {
            return false;
        }

        // Carbon 3 diffs are signed: now()->diffInSeconds(past) is negative, so the wait never ran
        // out and a stuck transcription held the reply forever (2026-11-16).
        if (\Illuminate\Support\Carbon::parse($pending)->diffInSeconds(now()) >= (int) config('agent.turns.transcript_wait_seconds')) {
            // Owner 2026-11-18: still not transcribed (its queue job lost or slow) - transcribe it here,
            // so the customer is not told "الصوت مش واضح" about a clear voice note.
            foreach (\App\Models\WhatsappMessage::where('turn_id', $job->id)->where('transcription_status', 'pending')->pluck('id') as $id) {
                try {
                    \App\Jobs\TranscribeVoiceMessage::dispatchSync($id);
                } catch (\Throwable $e) {
                    Log::warning('Inline voice transcription failed', ['message_id' => $id, 'error' => $e->getMessage()]);
                }
            }

            return false;
        }

        DB::table('whatsapp_message_jobs')->where('id', $job->id)->update([
            'status' => 'pending',
            'locked_at' => null,
            'attempts' => DB::raw('GREATEST(attempts - 1, 0)'),
            'process_after' => now()->addSeconds(2),
            'updated_at' => now(),
        ]);

        return true;
    }

    /**
     * Gemini rate-limited on every key: three immediate retries 5s apart
     * all failed inside a minute and the customer's "سلام" got no answer at
     * all. Retries back off through process_after (the worker is free
     * meanwhile) and the customer is told once that we're busy.
     *
     * Owner 2026-10-02: on 1/10 the quota ran out for three hours. After
     * five minutes each conversation went to staff ("زميلي هيرد عليك"),
     * nobody answered, and the bot sat silent twenty minutes at a time - a
     * customer got the busy notice six times. An outage is not something a
     * colleague can fix: the turn keeps retrying every minute until the AI
     * is back (agent.turns.outage_retry_minutes), then answers everything
     * he wrote, from where the conversation stopped.
     *
     * @return array{0: string, 1: ?\Illuminate\Support\Carbon}
     */
    private function scheduleTransientRetry(object $job): array
    {
        $attempts = (int) $job->attempts;
        $max = (int) config('agent.delivery.max_attempts');

        if ($attempts < $max) {
            return ['pending', now()->addSeconds(10 * $attempts)];
        }

        $this->tellCustomerWeAreBusy($job);

        $window = (int) config('agent.turns.outage_retry_minutes', 720);
        $startedAt = \Illuminate\Support\Carbon::parse($job->created_at ?? now());

        if ($startedAt->copy()->addMinutes($window)->isFuture()) {
            // Rechecking every minute made a provider outage consume thousands
            // of calls for the same small set of messages. Back off quickly,
            // then keep a 30-minute ceiling so recovery is still automatic.
            $outageAttempt = max(0, $attempts - $max);
            $delays = [120, 300, 600, 1200, 1800];
            $delay = $delays[min($outageAttempt, count($delays) - 1)];

            return ['pending', now()->addSeconds($delay)];
        }

        return ['failed', null];
    }

    private function tellCustomerWeAreBusy(object $job): void
    {
        $message = config('agent.fallback.message');
        $conversation = \App\Models\WhatsappConversation::find($job->whatsapp_conversation_id);

        if (blank($message) || ! $conversation) {
            return;
        }

        // One notice per outage, not one per stuck turn or every ten
        // minutes: told already since our last real reply = told.
        $lastReplyId = \App\Models\WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('direction', 'outgoing')->whereIn('sender_type', ['bot', 'human', 'human_phone', 'staff'])->max('id');

        $recentlyTold = \App\Models\WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('sender_type', 'system')
            ->where('text', $message)
            ->when($lastReplyId, fn ($q) => $q->where('id', '>', $lastReplyId))
            ->exists();

        if ($recentlyTold) {
            return;
        }

        try {
            app(DeliveryService::class)->deliverForConversation($conversation, ['messages' => [$message]], senderType: 'system');
        } catch (\Throwable $e) {
            Log::warning('Busy notice failed', ['job_id' => $job->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Take one of --workers slots, so several processes can run side by side.
     *
     * The old single global lock meant one slow reply (the reasoning model can
     * take tens of seconds) blocked every other customer waiting behind it.
     * Slots keep the process count bounded while letting independent
     * conversations be answered in parallel; ordering *within* one
     * conversation is preserved by claimNextJob(), not by this lock.
     */
    /** @var resource|null the AI turn slot this worker holds while it generates a reply */
    private $turnSlot = null;

    private function acquireTurnSlot(): bool
    {
        $slots = max(1, (int) config('agent.max_parallel_turns', 3));
        foreach (range(1, $slots) as $i) {
            $handle = fopen(storage_path("app/whatsapp-turn-slot.{$i}.lock"), 'c');
            if ($handle === false) {
                continue;
            }
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $this->turnSlot = $handle;

                return true;
            }
            fclose($handle);
        }

        return false;
    }

    private function releaseTurnSlot(): void
    {
        if ($this->turnSlot) {
            flock($this->turnSlot, LOCK_UN);
            fclose($this->turnSlot);
            $this->turnSlot = null;
        }
    }

    /** A bot worker stops between turns when its bot is off or deleted, or its supervisor died. */
    private function shouldKeepRunning(): bool
    {
        $parent = (int) $this->option('parent');
        if ($parent > 0 && function_exists('posix_getppid') && posix_getppid() !== $parent) {
            return false;
        }
        if (! $this->botId || time() - $this->botCheckedAt < 10) {
            return true;
        }
        $this->botCheckedAt = time();

        return \App\Models\WhatsappBot::whereKey($this->botId)->where('is_active', true)->exists();
    }

    private function acquireWorkerSlot(int $workers): bool
    {
        for ($slot = 1; $slot <= $workers; $slot++) {
            $handle = fopen(storage_path($this->botId ? "app/whatsapp-process-jobs.bot-{$this->botId}.lock" : "app/whatsapp-process-jobs.{$slot}.lock"), 'c');

            if ($handle === false) {
                continue;
            }

            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $this->lockHandle = $handle;
                $this->slot = $slot;

                return true;
            }

            fclose($handle);
        }

        return false;
    }

    /**
     * Claiming has to be serialised across workers, otherwise two of them can
     * read the same "no job is processing for this conversation" state and
     * both pick up a message from that conversation - answering the customer
     * out of order. Claiming takes milliseconds, processing takes seconds, so
     * holding this lock only around the claim costs nothing in throughput.
     */
    private function anythingClaimable(): bool
    {
        $staleBefore = now()->subMinutes(10);

        return DB::table('whatsapp_message_jobs')
            ->when($this->botId, fn ($q) => $q->where('whatsapp_bot_id', $this->botId))
            ->where(function ($q) use ($staleBefore) {
                $q->where(fn ($q) => $q->whereIn('status', ['pending', 'generated'])
                        ->where(fn ($q) => $q->whereNull('process_after')->orWhere('process_after', '<=', now())))
                    ->orWhere(fn ($q) => $q->where('status', 'processing')->whereNotNull('locked_at')->where('locked_at', '<', $staleBefore));
            })
            ->exists();
    }

    private function withClaimLock(callable $callback)
    {
        // a bot worker only competes with a shared-pool worker for its own bot's jobs
        $handle = fopen(storage_path($this->botId ? "app/whatsapp-process-jobs.claim.bot-{$this->botId}.lock" : 'app/whatsapp-process-jobs.claim.lock'), 'c');

        if ($handle === false) {
            return $callback();
        }

        flock($handle, LOCK_EX);

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function claimNextJob(): ?object
    {
        // Server 2026-11-10: three workers ran the full claim (six queries, a file lock and a
        // row lock) every second with nothing to take. One indexed existence check first.
        if (! $this->anythingClaimable()) {
            return null;
        }

        return $this->withClaimLock(fn () => DB::transaction(function () {
            $staleBefore = now()->subMinutes(10);

            // A worker that died mid-turn left its job 'processing' forever
            // (job 419). Past the attempt budget it is failed loudly;
            // otherwise it becomes claimable again below - re-running is
            // safe because tool calls are idempotent per turn.
            $abandoned = DB::table('whatsapp_message_jobs')
                ->when($this->botId, fn ($q) => $q->where('whatsapp_bot_id', $this->botId))
                ->where('status', 'processing')
                ->whereNotNull('locked_at')
                ->where('locked_at', '<', $staleBefore)
                ->where('attempts', '>=', (int) config('agent.delivery.max_attempts'))
                ->pluck('id');

            if ($abandoned->isNotEmpty()) {
                DB::table('whatsapp_message_jobs')->whereIn('id', $abandoned)->update([
                    'status' => 'failed', 'locked_at' => null, 'error' => 'ABANDONED_WHILE_PROCESSING', 'updated_at' => now(),
                ]);
                Log::error('WhatsApp turns abandoned while processing', ['job_ids' => $abandoned->all()]);
            }

            /*
             * Messages from the same customer must still be answered in the
             * order they arrived, so a conversation that another worker is
             * already busy with is skipped rather than picked up in parallel.
             * Jobs with no conversation id yet fall back to the sender.
             */
            // Owner 2026-11-18: a reply being delivered is 'generated' while the number waits out its
            // send gap - it was not counted, so a second worker took another turn of the same number
            // (often an old one) and both queued up in the bot: old replies went out between new ones.
            $busy = DB::table('whatsapp_message_jobs')
                ->whereIn('status', ['processing', 'generated'])
                ->whereNotNull('locked_at')
                ->where('locked_at', '>=', $staleBefore)
                ->get(['whatsapp_conversation_id', 'from', 'whatsapp_bot_id']);

            $busyConversationIds = $busy->pluck('whatsapp_conversation_id')->filter()->unique()->values()->all();
            $busySenders = $busy->pluck('from')->filter()->unique()->values()->all();
            // Owner 2026-11-13: a number sends one message every 1-2 minutes, so a second turn of
            // the same number would only hold a slot waiting - other numbers' customers go first.
            $busyBotIds = $busy->pluck('whatsapp_bot_id')->filter()->unique()->values()->all();

            // Owner 2026-11-10: bot 93 was restricted and logged out by late re-answers sent every
            // ~5 s (three workers at once, the backlog piled up behind live turns). One re-answer per
            // bot at a time, and the next one about a minute after the last was taken (owner).
            // Owner 2026-11-10 (anti-ban): a number that sent its hourly or daily cap of messages
            // takes no turn at all until the window frees - live ones wait too.
            $capped = \Illuminate\Support\Facades\Cache::remember('send_capped_bots', 30, function () {
                // Owner 2026-11-15: different customers answered per number, not messages.
                $hour = (int) config('agent.delivery.max_customers_per_hour', 150);
                $day = (int) config('agent.delivery.max_customers_per_day', 1500);
                $sent = fn ($since) => DB::table('whatsapp_messages')->where('direction', 'outgoing')
                    ->where('delivery_status', 'sent')->where('created_at', '>=', $since)
                    ->groupBy('whatsapp_bot_id')->selectRaw('whatsapp_bot_id, count(distinct whatsapp_conversation_id) n')->pluck('n', 'whatsapp_bot_id');

                // Owner 2026-11-15: a number WhatsApp warned uses half (first warning) or a quarter
                // (second warning) of its caps until the warning ends.
                $factor = \App\Models\WhatsappBot::whereNotNull('wa_limit_level')->get()
                    ->mapWithKeys(fn ($b) => [$b->id => \App\Models\WhatsappBot::limitFactor($b->activeLimit())]);
                // Owner 2026-11-18: a number back from a ban warms up on half its caps (config agent.delivery.warmup_bots).
                foreach ((array) config('agent.delivery.warmup_bots', []) as $botId => $warm) {
                    if (now()->lt(\Illuminate\Support\Carbon::parse($warm['until'])->endOfDay())) {
                        $factor[$botId] = min($factor[$botId] ?? 1.0, $warm['factor']);
                    }
                }
                $over = fn ($limit) => fn ($n, $botId) => $limit > 0 && $n >= max(1, (int) floor($limit * ($factor[$botId] ?? 1.0)));
                $capped = $sent(now()->subHour())->filter($over($hour))->keys()
                    ->merge($sent(now()->subDay())->filter($over($day))->keys())
                    ->unique()->values()->all();

                if ($capped !== []) {
                    Log::warning('WhatsApp send cap reached - turns wait', ['bot_ids' => $capped]);
                }

                return $capped;
            });
            // Owner 2026-11-15 (Baileys rc14): a number WhatsApp itself restricted (reachout timelock or
            // new-chat cap reached, see whatsapp:watch-sessions) takes no turn until it is lifted.
            $capped = array_values(array_unique(array_merge($capped,
                \App\Models\WhatsappBot::where('session_status', 'restricted')->pluck('id')->map(fn ($id) => (int) $id)->all(),
                \App\Models\WhatsappBot::whereIn('wa_limit_level', ['capped', 'timelock'])->get()
                    ->filter(fn ($b) => $b->activeLimit() !== null)->pluck('id')->map(fn ($id) => (int) $id)->all())));

            $backlogBlocked = DB::table('whatsapp_message_jobs')
                ->where('status', 'processing')->where('locked_at', '>=', $staleBefore)
                ->where('payload', 'like', '%"answer_waiting%')
                ->distinct()->pluck('whatsapp_bot_id')
                ->merge(\App\Models\WhatsappBot::pluck('id')->filter(fn ($id) => \Illuminate\Support\Facades\Cache::has('answer_waiting_gap_'.$id)))
                // a number waiting for its QR would fail every re-answer, and a failed one is never retried
                ->merge(\App\Models\WhatsappBot::where('session_status', '!=', 'connected')->pluck('id'))
                // Owner 2026-11-15: after WhatsApp's second warning only live customers are answered
                ->merge(\App\Models\WhatsappBot::where('wa_limit_level', 'second_warning')->get()
                    ->filter(fn ($b) => $b->activeLimit() !== null)->pluck('id'))
                ->unique()->values()->all();

            // when the customer started waiting on this turn (his first message in it)
            $first = 'coalesce((select min(m0.created_at) from whatsapp_messages m0 where m0.turn_id = whatsapp_message_jobs.id), whatsapp_message_jobs.created_at)';
            // numbers whose next customer turn is the longest waiting one (the last was the newest)
            $oldestNext = \App\Models\WhatsappBot::pluck('id')
                ->filter(fn ($id) => \Illuminate\Support\Facades\Cache::get('turn_pick_oldest_'.$id))
                ->map(fn ($id) => (int) $id)->values()->all();

            $job = DB::table('whatsapp_message_jobs')
                ->when($this->botId, fn ($q) => $q->where('whatsapp_bot_id', $this->botId))
                ->when($capped !== [], fn ($q) => $q->whereNotIn('whatsapp_bot_id', $capped))
                ->when($busyBotIds !== [], fn ($q) => $q->whereNotIn('whatsapp_bot_id', $busyBotIds))
                ->when($backlogBlocked !== [], fn ($q) => $q->where(fn ($q) => $q->whereNotIn('whatsapp_bot_id', $backlogBlocked)
                    ->orWhereNull('payload')->orWhere('payload', 'not like', '%"answer_waiting%')))
                // Paused bots keep their jobs and generated replies, but do
                // not consume model calls or block newly connected bots.
                ->whereIn('whatsapp_bot_id', \App\Models\WhatsappBot::where('is_active', true)->select('id'))
                // Owner 2026-11-18: a number that is not connected keeps its customers' turns until it
                // is back - a reply generated for it failed ("session not found") and was lost.
                ->whereNotIn('whatsapp_bot_id', \App\Models\WhatsappBot::whereNotNull('session_status')->where('session_status', '!=', 'connected')->select('id'))
                /*
                 * 'generated' = the reply/state mutation already happened
                 * and is stored in `result`; only delivery failed or never
                 * ran. Picking these up alongside 'pending' means a retry
                 * resends the already-generated result instead of running
                 * the whole pipeline again (see the split in handle()).
                 */
                ->where(function ($q) use ($staleBefore) {
                    $q->whereIn('status', ['pending', 'generated'])
                        ->orWhere(fn ($q) => $q->where('status', 'processing')
                            ->whereNotNull('locked_at')
                            ->where('locked_at', '<', $staleBefore));
                })
                // Both the debounce (pending) and the delivery-retry backoff
                // (generated) are expressed through process_after.
                ->where(function ($q) {
                    $q->whereNull('process_after')
                        ->orWhere('process_after', '<=', now());
                })
                ->where(function ($q) use ($staleBefore) {
                    $q->whereNull('locked_at')
                        ->orWhere('locked_at', '<', $staleBefore);
                })
                ->when($busyConversationIds !== [], function ($q) use ($busyConversationIds) {
                    $q->where(function ($q) use ($busyConversationIds) {
                        $q->whereNull('whatsapp_conversation_id')
                            ->orWhereNotIn('whatsapp_conversation_id', $busyConversationIds);
                    });
                })
                ->when($busySenders !== [], function ($q) use ($busySenders) {
                    $q->where(function ($q) use ($busySenders) {
                        $q->whereNull('from')
                            ->orWhereNotIn('from', $busySenders);
                    });
                })
                // Owner 2026-11-18 (replaces "a customer writing now first", 2026-11-10): whoever has
                // waited longest goes first, and every message he sent while waiting moves him up two
                // minutes (up to 10 messages) - a customer who wrote a lot is not left behind a new one.
                // Owner 2026-11-18 (second word, replaces "longest waiting first"): NEW messages first, in
                // the order they came - two customers who wrote at the same time are answered first come
                // first served - and the old ones only when no new customer is waiting.
                // Owner 2026-11-18 (third word, replaces "in the order they came"): with ~90 customers an
                // hour and a backlog, first-come-first-served left someone who wrote now 3 hours behind.
                // Now one NEW (the latest to write) and one OLD (waiting longest) by turns, per number, so
                // a customer who writes now is answered within a turn or two and the waiting ones still move.
                // 1) a customer who answered the bot right after its last message (live chat) - the bot
                //    stays with him until the conversation ends,
                ->select('whatsapp_message_jobs.*')
                ->selectRaw("exists (select 1 from whatsapp_messages o where o.whatsapp_conversation_id = whatsapp_message_jobs.whatsapp_conversation_id"
                    ." and o.direction = 'outgoing' and o.delivery_status = 'sent'"
                    ." and o.created_at between {$first} - interval ? second and {$first}"
                    // the app's clock, not MySQL's now(): the DB runs an hour behind Cairo (2026-11-18)
                    ." and o.created_at >= ?) as is_hot",
                    [(int) config('agent.turns.hot_reply_seconds', 120), now()->subMinutes(30)->toDateTimeString()])
                ->orderByDesc('is_hot')
                // 2) then every customer's own message before the old re-answers (whatsapp:answer-waiting
                //    backlog). Not by age: with a backlog over half an hour a fixed "new" window turned
                //    waiting customers into "old" and put 3 o'clock re-answers ahead of them (2026-11-18),
                ->orderByRaw("coalesce(payload, '') like '%\"answer_waiting%'")
                // 3) the latest to write or the longest waiting, by turns for each number.
                ->when($oldestNext !== [], fn ($q) => $q->orderByRaw(
                    'case when whatsapp_bot_id in ('.implode(',', $oldestNext).") then unix_timestamp({$first}) else -unix_timestamp({$first}) end"),
                    fn ($q) => $q->orderByRaw("unix_timestamp({$first}) desc"))
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (!$job) {
                return null;
            }

            DB::table('whatsapp_message_jobs')
                ->where('id', $job->id)
                ->update([
                    'status' => 'processing',
                    'locked_at' => now(),
                    'attempts' => DB::raw('attempts + 1'),
                    'updated_at' => now(),
                ]);

            $job->attempts = ((int) $job->attempts) + 1;

            // a customer turn picked by the new/old turns (not live chat, not a re-answer): flip the number
            if (!$job->is_hot && !str_contains((string) ($job->payload ?? ''), 'answer_waiting') && $job->whatsapp_bot_id) {
                $key = 'turn_pick_oldest_'.$job->whatsapp_bot_id;
                \Illuminate\Support\Facades\Cache::put($key, !\Illuminate\Support\Facades\Cache::get($key, false), now()->addDay());
            }
            unset($job->is_hot);

            if (str_contains((string) ($job->payload ?? ''), 'answer_waiting')) {
                $gap = random_int(60, 65);
                \Illuminate\Support\Facades\Cache::put('answer_waiting_gap_'.$job->whatsapp_bot_id, true, now()->addSeconds($gap));
            }

            return $job;
        }));
    }

    private function decodeJobResult(object $job): array
    {
        $result = is_string($job->result ?? null)
            ? json_decode($job->result, true)
            : ($job->result ?? []);

        return is_array($result) ? $result : [];
    }
}
