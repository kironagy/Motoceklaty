<?php

namespace App\Http\Controllers\Api;

use App\Domain\Conversations\IngestionService;
use App\Http\Controllers\Controller;
use App\Models\WhatsappBot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Owner 2026-11-14: customer messages arrive from Automoon (automooncrm.com/docs/dev/webhooks) instead
 * of our Node worker, and go into the same IngestionService the worker posts to - one pipeline.
 *
 * Automoon payload: {"event":"message.received","data":{"from","text","timestamp","deviceId"}}, signed
 * with the NBG-WEBHOOK-SECRET header. The optional fields read below (id, pushName, mediaUrl, ...) are
 * used only when Automoon sends them.
 */
class AutomoonWebhookController extends Controller
{
    public function __invoke(Request $request, IngestionService $ingestion)
    {
        $secret = (string) config('services.automoon.webhook_secret');

        if ($secret === '' || ! hash_equals($secret, (string) $request->header('NBG-WEBHOOK-SECRET'))) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $event = (string) $request->input('event');
        $data = (array) $request->input('data', []);

        if ($event !== 'message.received') {
            return response()->json(['ok' => true, 'ignored' => $event]);
        }

        $payload = self::toIngestPayload($data);

        if ($payload === null) {
            Log::warning('Automoon webhook: message ignored', ['device' => $data['deviceId'] ?? null, 'keys' => array_keys($data)]);

            return response()->json(['ok' => true, 'ignored' => true]);
        }

        $result = $ingestion->ingest($payload);

        if (! $result['ok'] && isset($result['errors'])) {
            return response()->json(['ok' => false, 'errors' => $result['errors']], 422);
        }

        return response()->json(['ok' => true, 'duplicate' => $result['duplicate'] ?? false]);
    }

    /** The worker's ingest payload for one Automoon message; null when it is not ours to answer. */
    public static function toIngestPayload(array $data): ?array
    {
        $bot = WhatsappBot::where('automoon_device_id', (string) ($data['deviceId'] ?? ''))->first();
        $from = preg_replace('/\D/', '', \Illuminate\Support\Str::before((string) ($data['from'] ?? ''), '@')) ?? '';
        $fromMe = filter_var($data['fromMe'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // groups and status broadcasts are not customers
        if (! $bot || $from === '' || str_contains((string) ($data['from'] ?? ''), '@g.us') || str_contains((string) ($data['from'] ?? ''), 'status@')) {
            return null;
        }

        $text = isset($data['text']) ? (string) $data['text'] : (isset($data['body']) ? (string) $data['body'] : null);
        $timestamp = (int) ($data['timestamp'] ?? time());
        $timestamp = $timestamp > 9999999999 ? intdiv($timestamp, 1000) : $timestamp;
        $media = self::media($data);
        $type = $media ? $media['media_type'] : ($text !== null && $text !== '' ? 'text' : 'unknown');

        $id = $data['id'] ?? $data['messageId'] ?? $data['key']['id'] ?? null;

        return array_filter([
            'bot_id' => $bot->id,
            // No id from Automoon: the same message (device, sender, time, text) is the same id, so a
            // webhook retry is still recognised as a duplicate.
            'wa_message_id' => is_scalar($id) && (string) $id !== ''
                ? (string) $id
                : 'am-'.sha1($bot->automoon_device_id.'|'.$from.'|'.$timestamp.'|'.$text),
            'chat_jid' => $from.'@s.whatsapp.net',
            'push_name' => $data['pushName'] ?? $data['name'] ?? null,
            'timestamp' => $timestamp,
            'type' => $type,
            'text' => $text ?? ($media['caption'] ?? null),
            'direction' => $fromMe ? 'outgoing' : 'incoming',
            'media' => $media ? [array_diff_key($media, ['caption' => true])] : null,
        ], fn ($v) => $v !== null);
    }

    private static function media(array $data): ?array
    {
        $url = $data['mediaUrl'] ?? $data['media_url'] ?? null;

        if (! is_string($url) || $url === '') {
            return null;
        }

        try {
            $response = Http::connectTimeout(10)->timeout(30)->get($url);
        } catch (\Throwable $e) {
            Log::warning('Automoon webhook: media download failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $mime = (string) ($data['mimetype'] ?? $data['mimeType'] ?? $response->header('Content-Type') ?: 'application/octet-stream');
        $mime = trim(explode(';', $mime)[0]);
        $kind = match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'audio/') => 'audio',
            str_starts_with($mime, 'video/') => 'video',
            default => 'document',
        };

        return ['media_type' => $kind, 'mime' => $mime, 'base64' => base64_encode($response->body()), 'caption' => $data['caption'] ?? null];
    }
}
