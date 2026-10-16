<?php

namespace App\Http\Controllers;

use App\Models\Letter;
use Illuminate\View\View;

class PrintRaedLetterController extends Controller
{
    public function __invoke(Letter $letter): View
    {
        // منع أي مستخدم غير مسجّل من الوصول لرابط الطباعة مباشرة.
        abort_unless(\App\Support\FinanceAccess::hasAccess(), 403);

        $letter->loadMissing('inventory.product', 'inventory.warehouse');

        return view('letters.print-raed', compact('letter'));
    }
}