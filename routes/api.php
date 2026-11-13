<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WhatsappBotController;

Route::post('/whatsapp/incoming-message', [WhatsappBotController::class, 'incomingMessage']);
// Owner 2026-11-14: customer messages from the Automoon gateway (WHATSAPP_GATEWAY=automoon)
Route::post('/automoon/webhook', \App\Http\Controllers\Api\AutomoonWebhookController::class);
Route::get('/whatsapp/latest-active-bot', [WhatsappBotController::class, 'latestActiveBotId']);
Route::get('/test', function () {
    return response()->json(['ok' => true]);
});
