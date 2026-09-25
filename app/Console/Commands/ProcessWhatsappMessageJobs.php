<?php

namespace App\Console\Commands;

use App\Agent\Runtime\TurnProcessor;
use App\Domain\Conversations\DeliveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessWhatsappMessageJobs extends Command
{
    protected $signature = 'whatsapp:process-jobs {--sleep=1} {--workers=3}';
    protected $description = 'Process queued WhatsApp AI message jobs';

    /** @var resource|null kept open for the process lifetime so flock() stays held */
    private $lockHandle = null;

    /** 1-based slot this process holds, for log lines. */
    private int $slot = 0;

    public function handle(): int
    {
        $workers = max(1, (int) $this->option('workers'));

        if (! $this->acquireWorkerSlot($workers)) {
            $this->error("All {$workers} whatsapp:process-jobs slots are already taken. Exiting.");
            Log::warning('whatsapp:process-jobs refused to start: no free slot', [
                'workers' => $workers,
            ]);

            return self::FAILURE;
        }

        $this->info("WhatsApp queue worker started (slot {$this->slot}/{$workers})");

        while (true) {
            // dashboard settings (bot on/off, model, limits) take effect
            // without restarting this long-running worker
            \App\Domain\Settings\AgentSettings::apply();

            if (! config('agent.enabled')) {
                sleep((int) $this->option('sleep'));
                continue;
            }

            $job = $this->claimNextJob();

            if (!$job) {
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

        if ($pending === null
            || now()->diffInSeconds(\Illuminate\Support\Carbon::parse($pending)) >= (int) config('agent.turns.transcript_wait_seconds')) {
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
     * all. Retries now back off through process_after (the worker is free
     * meanwhile); when the normal attempts run out the customer is told once
     * that we're busy and the turn keeps retrying for a few more minutes
     * before it finally fails and goes to staff.
     *
     * @return array{0: string, 1: ?\Illuminate\Support\Carbon}
     */
    private function scheduleTransientRetry(object $job): array
    {
        $attempts = (int) $job->attempts;
        $max = (int) config('agent.delivery.max_attempts');
        $lateRetries = 4;

        if ($attempts < $max) {
            return ['pending', now()->addSeconds(10 * $attempts)];
        }

        if ($attempts === $max) {
            $this->tellCustomerWeAreBusy($job);
        }

        if ($attempts < $max + $lateRetries) {
            return ['pending', now()->addSeconds(60)];
        }

        $conversation = \App\Models\WhatsappConversation::find($job->whatsapp_conversation_id);

        if ($conversation && $conversation->status !== 'awaiting_agent') {
            app(\App\Domain\Handoff\HandoffService::class)->handOffForFailures($conversation, 'AI_UNAVAILABLE');
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

        // One notice per outage, not one per stuck turn.
        $recentlyTold = \App\Models\WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('sender_type', 'system')
            ->where('text', $message)
            ->where('created_at', '>=', now()->subMinutes(10))
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
    private function acquireWorkerSlot(int $workers): bool
    {
        for ($slot = 1; $slot <= $workers; $slot++) {
            $handle = fopen(storage_path("app/whatsapp-process-jobs.{$slot}.lock"), 'c');

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
    private function withClaimLock(callable $callback)
    {
        $handle = fopen(storage_path('app/whatsapp-process-jobs.claim.lock'), 'c');

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
        return $this->withClaimLock(fn () => DB::transaction(function () {
            $staleBefore = now()->subMinutes(10);

            // A worker that died mid-turn left its job 'processing' forever
            // (job 419). Past the attempt budget it is failed loudly;
            // otherwise it becomes claimable again below - re-running is
            // safe because tool calls are idempotent per turn.
            $abandoned = DB::table('whatsapp_message_jobs')
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
            $busy = DB::table('whatsapp_message_jobs')
                ->where('status', 'processing')
                ->where('locked_at', '>=', $staleBefore)
                ->get(['whatsapp_conversation_id', 'from']);

            $busyConversationIds = $busy->pluck('whatsapp_conversation_id')->filter()->unique()->values()->all();
            $busySenders = $busy->pluck('from')->filter()->unique()->values()->all();

            $job = DB::table('whatsapp_message_jobs')
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
