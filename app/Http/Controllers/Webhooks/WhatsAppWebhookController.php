<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\WhatsappBot;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        // Verification
        if ($request->isMethod('get')) {
            $verifyToken = 'motocyklaty_ai_token';

            if (
                $request->get('hub_mode') === 'subscribe' &&
                $request->get('hub_verify_token') === $verifyToken
            ) {
                return response($request->get('hub_challenge'), 200);
            }

            return response('Invalid verification token', 403);
        }

        $payload = $request->all();

        Log::info('WEBHOOK HIT');
        Log::info('WEBHOOK PAYLOAD', $payload);

        $value = data_get($payload, 'entry.0.changes.0.value', []);

        $phoneNumberId = data_get($value, 'metadata.phone_number_id');

        // 1) Status updates only
        $statuses = data_get($value, 'statuses', []);
        if (!empty($statuses)) {
            Log::info('STATUS EVENT', [
                'phoneNumberId' => $phoneNumberId,
                'statuses' => $statuses,
            ]);

            return response()->json(['status' => 'status_received'], 200);
        }

        // 2) Incoming messages
        $from = data_get($value, 'messages.0.from');
        $text = data_get($value, 'messages.0.text.body');
        $type = data_get($value, 'messages.0.type');

        Log::info('PARSED DATA', [
            'phoneNumberId' => $phoneNumberId,
            'from' => $from,
            'text' => $text,
            'type' => $type,
        ]);

        if (! $phoneNumberId || ! $from) {
            return response()->json(['status' => 'ignored'], 200);
        }

        $bot = WhatsappBot::where('whatsapp_phone_number_id', $phoneNumberId)
            ->where('is_active', true)
            ->first();

        if (! $bot) {
            Log::warning("No active bot found for phone_number_id: {$phoneNumberId}");
            return response()->json(['status' => 'bot_not_found'], 200);
        }

        // رد مبدئي بسيط
        if ($type === 'text' && $text) {
            app(WhatsAppService::class)->sendText(
                $from,
                "اهلا بيك يا فندم 👋\nوصلتني رسالتك: {$text}",
                $bot->whatsapp_phone_number_id
            );
        } else {
            app(WhatsAppService::class)->sendText(
                $from,
                "اهلا بيك يا فندم 👋\nابعتلي رسالة نصية أو كمل بياناتك.",
                $bot->whatsapp_phone_number_id
            );
        }

        return response()->json(['status' => 'ok'], 200);
    }
}
