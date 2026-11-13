<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Owner 2026-11-14: WhatsApp through the Automoon gateway (automooncrm.com/docs/dev/api) instead of
 * our own Node worker. On with WHATSAPP_GATEWAY=automoon; the worker code stays untouched so setting
 * it back to 'worker' restores the old path.
 *
 * Speaks the worker's language to its callers: send() takes the worker endpoint + payload
 * (/send-message, /send-media-items) and answers like the worker does ({ok, wa_message_id}), so
 * DeliveryService and the rest keep their retry and dedupe logic unchanged.
 *
 * Automoon API: header X-API-KEY, POST /whatsapp/send {to, body, deviceId},
 * POST /whatsapp/send-media {to, mediaUrl, caption, deviceId}, GET /whatsapp/devices.
 */
class AutomoonGateway
{
    public static function enabled(): bool
    {
        return config('services.whatsapp.gateway') === 'automoon';
    }

    public function send(string $endpoint, array $payload, int $timeout = 60): Response
    {
        $bot = WhatsappBot::find($payload['bot_id'] ?? null);
        $deviceId = $bot?->automoon_device_id;

        if (! $deviceId) {
            return $this->answer(422, ['ok' => false, 'error' => 'bot '.($payload['bot_id'] ?? '?').' has no Automoon device id']);
        }

        $to = $this->phoneFor($bot, (string) ($payload['jid'] ?? ''));

        if ($to === '') {
            return $this->answer(422, ['ok' => false, 'error' => 'no phone number for jid '.($payload['jid'] ?? '')]);
        }

        return match ($endpoint) {
            '/send-message' => $this->sendText($deviceId, $to, (string) ($payload['message'] ?? ''), $timeout),
            '/send-media-items' => $this->sendMediaItems($deviceId, $to, $payload['media_items'] ?? [], $timeout),
            // Worker-only housekeeping (archiving chats etc.) has no Automoon equivalent.
            default => $this->answer(200, ['ok' => true, 'skipped' => $endpoint]),
        };
    }

    /** @return int[]|null bot ids whose Automoon device is connected, null when Automoon can't say */
    public function connectedBotIds(): ?array
    {
        try {
            $response = $this->client(15)->get('/whatsapp/devices');
        } catch (\Throwable $e) {
            Log::warning('Automoon: devices unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Automoon: devices failed', ['status' => $response->status(), 'body' => Str::limit($response->body(), 300)]);

            return null;
        }

        $json = $response->json();
        $devices = $json['data']['devices'] ?? $json['devices'] ?? $json['data'] ?? $json;

        if (! is_array($devices) || ! array_is_list($devices)) {
            return null;
        }

        $connected = collect($devices)
            ->filter(fn ($d) => is_array($d) && self::deviceConnected($d))
            ->map(fn ($d) => (string) ($d['deviceId'] ?? $d['id'] ?? $d['_id'] ?? ''))
            ->filter()
            ->all();

        return WhatsappBot::whereIn('automoon_device_id', $connected)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public static function deviceConnected(array $device): bool
    {
        if (array_key_exists('connected', $device)) {
            return (bool) $device['connected'];
        }

        return in_array(strtolower((string) ($device['status'] ?? $device['state'] ?? '')), ['connected', 'open', 'online', 'active', 'ready'], true);
    }

    private function sendText(string $deviceId, string $to, string $body, int $timeout): Response
    {
        return $this->post('/whatsapp/send', ['to' => $to, 'body' => $body, 'deviceId' => $deviceId], $timeout);
    }

    private function sendMediaItems(string $deviceId, string $to, array $items, int $timeout): Response
    {
        $ids = [];

        foreach ($items as $item) {
            $url = $item['url'] ?? $item['media_url'] ?? null;

            if (! $url) {
                return $this->answer(422, ['ok' => false, 'error' => 'media item without a public url']);
            }

            $response = $this->post('/whatsapp/send-media', array_filter([
                'to' => $to,
                'mediaUrl' => $url,
                'caption' => $item['caption'] ?? null,
                'deviceId' => $deviceId,
            ], fn ($v) => $v !== null && $v !== ''), $timeout);

            if (! $response->json('ok')) {
                return $response;
            }

            $ids[] = $response->json('wa_message_id');
        }

        return $this->answer(200, ['ok' => true, 'wa_message_ids' => $ids, 'wa_message_id' => $ids[0] ?? null]);
    }

    /** One Automoon call, answered in the worker's shape. */
    private function post(string $path, array $body, int $timeout): Response
    {
        try {
            $response = $this->client($timeout)->post($path, $body);
        } catch (\Throwable $e) {
            return $this->answer(503, ['ok' => false, 'error' => 'Automoon unreachable: '.$e->getMessage()]);
        }

        $json = $response->json() ?? [];
        $ok = $response->successful() && ($json['success'] ?? $json['ok'] ?? true) !== false
            && ! in_array($json['status'] ?? null, ['fail', 'error'], true);

        if (! $ok) {
            return $this->answer($response->successful() ? 502 : $response->status(), [
                'ok' => false,
                'error' => 'Automoon '.$response->status().': '.Str::limit($response->body(), 500),
            ]);
        }

        return $this->answer(200, ['ok' => true, 'wa_message_id' => self::messageId($json)]);
    }

    /** Automoon's id for the sent message when it gives one; ours otherwise (the column is unique). */
    public static function messageId(array $json): string
    {
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];

        foreach ([$data['messageId'] ?? null, $data['id'] ?? null, $data['key']['id'] ?? null, $json['messageId'] ?? null, $json['id'] ?? null] as $id) {
            if (is_scalar($id) && (string) $id !== '') {
                return (string) $id;
            }
        }

        return 'am-'.Str::uuid();
    }

    /** International digits Automoon wants ("201012345678"); an @lid chat goes to its stored real phone. */
    private function phoneFor(WhatsappBot $bot, string $jid): string
    {
        if (str_contains($jid, '@') && ! str_ends_with($jid, '@s.whatsapp.net')) {
            $customer = Customer::where('whatsapp_bot_id', $bot->id)
                ->where(fn ($q) => $q->where('jid', $jid)->orWhere('lid_jid', $jid))->first();
            $conversation = $customer ? WhatsappConversation::where('customer_id', $customer->id)->latest('id')->first() : null;
            $jid = (string) ($conversation?->real_phone ?: $customer?->phone ?: $conversation?->phone ?: '');
            // the stored phone of an @lid chat can itself be the lid number - not a phone
            if ($jid === '' || str_contains($jid, '@')) {
                return '';
            }
        }

        $digits = preg_replace('/\D/', '', Str::before($jid, '@')) ?? '';

        // A local Egyptian number (01xxxxxxxxx) - Automoon wants it with the country code.
        if (strlen($digits) === 11 && str_starts_with($digits, '01')) {
            $digits = '2'.$digits;
        }

        return $digits;
    }

    private function client(int $timeout)
    {
        return Http::baseUrl(config('services.automoon.base_url'))
            ->connectTimeout(10)->timeout($timeout)
            ->withHeaders(['X-API-KEY' => (string) config('services.automoon.api_key'), 'Accept' => 'application/json'])
            ->asJson();
    }

    private function answer(int $status, array $body): Response
    {
        return new Response(new PsrResponse($status, ['Content-Type' => 'application/json'], json_encode($body)));
    }
}
