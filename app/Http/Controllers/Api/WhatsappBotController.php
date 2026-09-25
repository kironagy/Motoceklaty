<?php

namespace App\Http\Controllers\Api;

use App\Domain\Conversations\IngestionService;
use App\Http\Controllers\Controller;
use App\Models\WhatsappBot;
use Illuminate\Http\Request;

class WhatsappBotController extends Controller
{
    public function incomingMessage(Request $request, IngestionService $ingestion)
    {
        if ($request->header('X-BOT-TOKEN') !== config('services.whatsapp.bot_token')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $result = $ingestion->ingest($request->all());

        if (! $result['ok'] && isset($result['errors'])) {
            return response()->json(['ok' => false, 'errors' => $result['errors']], 422);
        }

        return response()->json([
            'ok' => true,
            'duplicate' => $result['duplicate'] ?? false,
        ]);
    }

    public function latestActiveBotId(Request $request)
    {
        if ($request->header('X-BOT-TOKEN') !== config('services.whatsapp.bot_token')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $bot = WhatsappBot::where('is_active', true)->latest('id')->first()
            ?? WhatsappBot::latest('id')->first();

        return response()->json([
            'ok' => (bool) $bot,
            'bot_id' => $bot?->id,
            // Node starts every active bot it has a linked session for, so a
            // restart doesn't pick an unlinked newer bot over the live one.
            'active_bot_ids' => WhatsappBot::where('is_active', true)->orderByDesc('id')->pluck('id')->all(),
        ]);
    }

    /**
     * Placeholder for the new agent runtime. T17 replaces this with the
     * real TurnProcessor-driven pipeline; this just keeps the return shape
     * the queue worker understands until then.
     */
    public function processQueuedWhatsappJob(object $job): array
    {
        return ['reply' => null, 'image' => null, 'images' => []];
    }
}
