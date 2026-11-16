<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WhatsappBotController;

Route::post('/whatsapp/incoming-message', [WhatsappBotController::class, 'incomingMessage']);
Route::get('/whatsapp/latest-active-bot', [WhatsappBotController::class, 'latestActiveBotId']);
Route::get('/test', function () {
    return response()->json(['ok' => true]);
});

// Aman uploader Chrome extension (aman-extension/) - the employee signs in with their motobot email + password.
Route::post('/aman/login', [\App\Http\Controllers\Api\AmanExtensionController::class, 'login'])->middleware('throttle:10,1');
Route::prefix('aman')->middleware(\App\Http\Middleware\AmanExtensionToken::class)->group(function () {
    Route::get('/me', [\App\Http\Controllers\Api\AmanExtensionController::class, 'me']);
    Route::get('/recipes', [\App\Http\Controllers\Api\AmanExtensionController::class, 'recipes']);
    Route::put('/recipes/{type}', [\App\Http\Controllers\Api\AmanExtensionController::class, 'saveRecipe']);
    Route::post('/logout', [\App\Http\Controllers\Api\AmanExtensionController::class, 'logout']);
    Route::get('/queue', [\App\Http\Controllers\Api\AmanExtensionController::class, 'queue']);
    Route::get('/report', [\App\Http\Controllers\Api\AmanExtensionController::class, 'report']);
    Route::post('/requests/{id}/claim', [\App\Http\Controllers\Api\AmanExtensionController::class, 'claim'])->whereNumber('id');
    Route::get('/uploads/{upload}/files/{field}', [\App\Http\Controllers\Api\AmanExtensionController::class, 'file'])->name('aman.file');
    Route::post('/uploads/{upload}/result', [\App\Http\Controllers\Api\AmanExtensionController::class, 'result']);
    Route::post('/uploads/{upload}/alive', [\App\Http\Controllers\Api\AmanExtensionController::class, 'alive']);
});
