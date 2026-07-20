<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\WhatsappBotController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessWhatsappMessageJobs extends Command
{
    protected $signature = 'whatsapp:process-jobs {--sleep=2}';
    protected $description = 'Process queued WhatsApp AI message jobs';

    public function handle(): int
    {
        $this->info('WhatsApp queue worker started');

        while (true) {
            $job = $this->claimNextJob();

            if (!$job) {
                sleep((int) $this->option('sleep'));
                continue;
            }

            try {
                $controller = app(WhatsappBotController::class);

                $result = $controller->processQueuedWhatsappJob($job);

                if (!is_array($result)) {
                    $result = [];
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

                DB::table('whatsapp_message_jobs')
                    ->where('id', $job->id)
                    ->update([
                        'status' => ((int) $job->attempts >= 3) ? 'failed' : 'pending',
                        'locked_at' => null,
                        'error' => $e->getMessage(),
                        'updated_at' => now(),
                    ]);

                sleep(1);
            }
        }
    }

    private function claimNextJob(): ?object
    {
        return DB::transaction(function () {
            $job = DB::table('whatsapp_message_jobs')
                ->where('status', 'pending')
                ->where(function ($q) {
                    $q->whereNull('locked_at')
                        ->orWhere('locked_at', '<', now()->subMinutes(10));
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
        });
    }

    private function sendWhatsappResult(object $job, array $result): void
    {
        $reply = trim((string) ($result['reply'] ?? ''));

        if ($reply !== '') {
            $this->sendWhatsappText($job, $reply);
        }

        $mediaItems = $this->extractMediaItemsFromResult($result);

        if (!empty($mediaItems)) {
            $this->sendWhatsappMediaItems($job, $mediaItems);
        }
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
        $url = rtrim(env('WHATSAPP_WORKER_URL', 'http://127.0.0.1:3010'), '/') . '/send-message';

        $payload = $this->decodeJobPayload($job);

        $response = Http::connectTimeout(10)
            ->timeout(60)
            ->withHeaders([
                'X-BOT-TOKEN' => env('BOT_TOKEN'),
                'Accept' => 'application/json',
            ])
            ->post($url, [
                'bot_id' => (string) $job->whatsapp_bot_id,
                'jid' => $job->reply_jid ?: $job->from,
                'message' => $reply,
                'quoted_message' => $payload['quoted_message'] ?? null,
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
        $url = rtrim(env('WHATSAPP_WORKER_URL', 'http://127.0.0.1:3010'), '/') . '/send-media-items';

        $response = Http::connectTimeout(10)
            ->timeout(120)
            ->withHeaders([
                'X-BOT-TOKEN' => env('BOT_TOKEN'),
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
}
