<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\WhatsappBotController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
            $job = $this->claimNextJob();

            if (!$job) {
                sleep((int) $this->option('sleep'));
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

                    $controller = app(WhatsappBotController::class);

                    $result = $controller->processQueuedWhatsappJob($job);

                    if (!is_array($result)) {
                        $result = [];
                    }

                    $this->line(sprintf(
                        '[%s] AI result for job #%d: reply=%s images=%d',
                        now()->toDateTimeString(),
                        $job->id,
                        !empty(trim((string) ($result['reply'] ?? ''))) ? 'yes' : 'no',
                        count($result['image_items'] ?? $result['images'] ?? [])
                    ));

                    /*
                     * Persist BEFORE attempting delivery. If sendWhatsappResult()
                     * throws below, the catch block leaves this job at
                     * status='generated' (not 'pending') so a retry skips
                     * straight to resending this exact result.
                     */
                    DB::table('whatsapp_message_jobs')
                        ->where('id', $job->id)
                        ->update([
                            'status' => 'generated',
                            'result' => json_encode($result, JSON_UNESCAPED_UNICODE),
                            'updated_at' => now(),
                        ]);
                    $generationSucceeded = true;
                }

                $this->sendWhatsappResult($job, $result);

                DB::table('whatsapp_message_jobs')
                    ->where('id', $job->id)
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
                $failureStatus = ((int) $job->attempts >= 3)
                    ? 'failed'
                    : ($generationSucceeded ? 'generated' : 'pending');

                DB::table('whatsapp_message_jobs')
                    ->where('id', $job->id)
                    ->update([
                        'status' => $failureStatus,
                        'locked_at' => null,
                        'error' => $e->getMessage(),
                        'updated_at' => now(),
                    ]);

                /*
                 * A transient AI failure (rate limit, a key mid-cooldown,
                 * a flaky call) is retried by design - but re-claiming
                 * the same job a second later usually walks straight back
                 * into whatever caused it, which burns the one retry the
                 * customer has before the turn goes to a human. Give the
                 * provider a few seconds to come back first.
                 */
                sleep($e instanceof \App\Exceptions\TransientAiFailure ? 5 : 1);
            }
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
                ->whereIn('status', ['pending', 'generated'])
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

    private function sendWhatsappResult(object $job, array $result): void
    {
        foreach ($this->textMessagesFromResult($result) as $index => $text) {
            /*
             * A human answering two questions sends two messages, with a
             * beat in between - not one block. The pause also keeps WhatsApp
             * from reordering messages sent back-to-back.
             */
            if ($index > 0) {
                usleep(1_200_000);
            }

            $this->sendWhatsappText($job, $text);
        }

        $mediaItems = $this->extractMediaItemsFromResult($result);

        if (!empty($mediaItems)) {
            $this->sendWhatsappMediaItems($job, $mediaItems);
        }
    }

    /**
     * One entry per WhatsApp message to send. 'replies' is set only when the
     * customer asked for more than one thing in the same message; everything
     * else still sends the single 'reply'.
     */
    private function textMessagesFromResult(array $result): array
    {
        $replies = $result['replies'] ?? null;

        if (is_array($replies) && ! empty($replies)) {
            $texts = array_values(array_filter(
                array_map(fn ($reply) => trim((string) $reply), $replies),
                fn (string $reply) => $reply !== ''
            ));

            if (! empty($texts)) {
                return $texts;
            }
        }

        $reply = trim((string) ($result['reply'] ?? ''));

        return $reply === '' ? [] : [$reply];
    }

    private function extractMediaItemsFromResult(array $result): array
    {
        if (!empty($result['image_items']) && is_array($result['image_items'])) {
            return array_values(array_filter($result['image_items'], function ($item) {
                return is_array($item) && !empty($item['url']);
            }));
        }

        if (!empty($result['images']) && is_array($result['images'])) {
            return collect($result['images'])
                ->filter()
                ->unique()
                ->map(fn ($url) => [
                    'type' => 'image',
                    'url' => $url,
                    'caption' => '',
                ])
                ->values()
                ->all();
        }

        if (!empty($result['image'])) {
            return [[
                'type' => 'image',
                'url' => $result['image'],
                'caption' => '',
            ]];
        }

        return [];
    }

    private function sendWhatsappText(object $job, string $reply): void
    {
        $url = config('services.whatsapp.worker_url') . '/send-message';

        $payload = $this->decodeJobPayload($job);

        /*
         * "رد بـ reply مش رسالة عادية" لما الرسالة دي جت في نص burst
         * (فيه Job تاني من نفس المحادثة كان لسه منتظر/بيتعالج وقت ما
         * الرسالة دي وصلت - علّمها incomingMessage() بـ quote_reply).
         * الـ wa_message_id بتاع نفس الرسالة دي هو اللي المفروض نعمله
         * quote، مش أي حاجة تانية - Node بيحتفظ بالرسالة الخام دي في
         * الذاكرة ويقدر يبنيها quoted كاملة منها.
         */
        $quotedMessage = (! empty($payload['quote_reply']) && ! empty($payload['wa_message_id']))
            ? $payload['wa_message_id']
            : null;

        $response = Http::connectTimeout(10)
            ->timeout(60)
            ->withHeaders([
                'X-BOT-TOKEN' => config('services.whatsapp.bot_token'),
                'Accept' => 'application/json',
            ])
            ->post($url, [
                'bot_id' => (string) $job->whatsapp_bot_id,
                'jid' => $job->reply_jid ?: $job->from,
                'message' => $reply,
                'quoted_message' => $quotedMessage,
            ]);

        Log::info('WHATSAPP SEND TEXT RESPONSE', [
            'job_id' => $job->id,
            'url' => $url,
            'bot_id' => $job->whatsapp_bot_id,
            'jid' => $job->reply_jid ?: $job->from,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if (!$response->successful() || !$response->json('ok')) {
            throw new \RuntimeException(
                'WhatsApp text send failed: ' . $response->status() . ' - ' . $response->body()
            );
        }
    }

    private function sendWhatsappMediaItems(object $job, array $mediaItems): void
    {
        $url = config('services.whatsapp.worker_url') . '/send-media-items';

        $response = Http::connectTimeout(10)
            ->timeout(120)
            ->withHeaders([
                'X-BOT-TOKEN' => config('services.whatsapp.bot_token'),
                'Accept' => 'application/json',
            ])
            ->post($url, [
                'bot_id' => (string) $job->whatsapp_bot_id,
                'jid' => $job->reply_jid ?: $job->from,
                'media_items' => $mediaItems,
            ]);

        Log::info('WHATSAPP SEND MEDIA RESPONSE', [
            'job_id' => $job->id,
            'url' => $url,
            'bot_id' => $job->whatsapp_bot_id,
            'jid' => $job->reply_jid ?: $job->from,
            'count' => count($mediaItems),
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if (!$response->successful() || !$response->json('ok')) {
            throw new \RuntimeException(
                'WhatsApp media send failed: ' . $response->status() . ' - ' . $response->body()
            );
        }
    }

    private function decodeJobPayload(object $job): array
    {
        $payload = is_string($job->payload ?? null)
            ? json_decode($job->payload, true)
            : ($job->payload ?? []);

        return is_array($payload) ? $payload : [];
    }

    private function decodeJobResult(object $job): array
    {
        $result = is_string($job->result ?? null)
            ? json_decode($job->result, true)
            : ($job->result ?? []);

        return is_array($result) ? $result : [];
    }
}
