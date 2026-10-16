<?php

// Showroom / finance pages merged from env.motocyklaty.store. All of them need the dashboard login.

use App\Http\Controllers\LetterReturnController;
use App\Http\Controllers\PrintRaedLetterController;
use App\Models\Letter;
use App\Support\FinanceAccess;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth:filament'])->group(function () {
    Route::get('/admin/letters/{letter}/print-sale', function (Letter $letter) {
        abort_unless(FinanceAccess::hasAccess(), 403);

        return view('prints.sale-contract', compact('letter'));
    })->name('letters.print-sale');

    Route::get('/letters/{letter}/print-raed', PrintRaedLetterController::class)
        ->name('letters.print-raed');

    Route::post('/letters/{letter}/return', [LetterReturnController::class, 'store'])
        ->name('letters.return');
});
