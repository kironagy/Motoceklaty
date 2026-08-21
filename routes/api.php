<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WhatsappBotController;

Route::post('/whatsapp/incoming-message', [WhatsappBotController::class, 'incomingMessage']);
Route::get('/whatsapp/latest-active-bot', [WhatsappBotController::class, 'latestActiveBotId']);
Route::get('/test', function () {
    return response()->json(['ok' => true]);
});
