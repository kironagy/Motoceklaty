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
 * Automoon payload: {"event":"message.received","delivery_id","payload":{"deviceId","messageId",
 * "from":{"jid","phone","name"},"text","mediaUrl","mimetype","timestamp"}}, signed with
 * X-Automoon-Signature: sha256=HMAC-SHA256(raw body, whsec_ secret). The older documented shape
 * ("data" + NBG-WEBHOOK-SECRET header) is still accepted.
 */
class AutomoonWebhookController extends Controller
{
    public function __invoke(Request $request, IngestionService $ingestion)
    {
        $secret = (string) config('services.automoon.webhook_secret');

        if (! self::signed($request, $secret)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $event = (string) $request->input('event');
        $data = (array) ($request->input('payload') ?? $request->input('data', []));

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

    private static function signed(Request $request, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $signature = (string) $request->header('X-Automoon-Signature');

        if ($signature !== '') {
            return hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature);
        }

        return hash_equals($secret, (string) $request->header('NBG-WEBHOOK-SECRET'));
    }

    /** The worker's ingest payload for one Automoon message; null when it is not ours to answer. */
    public static function toIngestPayload(array $data): ?array
    {
        $bot = WhatsappBot::where('automoon_device_id', (string) ($data['deviceId'] ?? ''))->first();
        // "from" is {jid, phone, name} now, a plain number/jid in the older shape
        $sender = is_array($data['from'] ?? null) ? $data['from'] : ['jid' => (string) ($data['from'] ?? '')];
        $jid = (string) ($sender['jid'] ?? '');
        // an @lid jid is not a phone; Automoon gives the real phone next to it
        $from = preg_replace('/\D/', '', (string) (($sender['phone'] ?? null) ?: \Illuminate\Support\Str::before($jid, '@'))) ?? '';
        $fromMe = filter_var($data['fromMe'] ?? false, FILTER_VALIDATE_BOOLEAN) || ($data['direction'] ?? null) === 'outbound';

        // groups and status broadcasts are not customers
        if (! $bot || $from === '' || str_contains($jid, '@g.us') || str_contains($jid, 'status@')) {
            return null;
        }

        $text = isset($data['text']) ? (string) $data['text'] : (isset($data['body']) ? (string) $data['body'] : null);
        $timestamp = $data['timestamp'] ?? time();
        $timestamp = is_numeric($timestamp) ? (int) $timestamp : (strtotime((string) $timestamp) ?: time());
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
            // Owner 2026-11-14: keep the real jid - an @lid customer is the same customer the worker
            // stored as "<lid>@lid" (Automoon's "phone" for them is only the lid digits).
            'chat_jid' => preg_match('/^\d+@(lid|s\.whatsapp\.net)$/', $jid) ? $jid : $from.'@s.whatsapp.net',
            'push_name' => $sender['name'] ?? $data['pushName'] ?? $data['name'] ?? null,
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
