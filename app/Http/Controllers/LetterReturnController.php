<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\Letter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LetterReturnController extends Controller
{
    public function store(Request $request, Letter $letter)
    {
        $user = auth()->user();

        $isSuperAdmin = \App\Support\Access::full(\App\Filament\Resources\LetterResource::class, $user);

        if (! $isSuperAdmin) {
            return back()->with(
                'error',
                'لا يمكن تنفيذ المرتجع. محتاج صلاحية كاملة على الجوابات.'
            );
        }

        DB::transaction(function () use ($letter) {
            InventoryItem::create([
                'inventory_id'      => $letter->inventory_id,
                'chassis_image'     => $letter->chassis_image,
                'engine_image'      => $letter->engine_image,
                'customer_id_image' => $letter->customer_id_image,
                'color'             => $letter->color,
                'customer_name'     => $letter->customer_name,
                'national_id'       => $letter->national_id,
                'phone'             => $letter->phone,
                'address'           => $letter->address,
                'cash'              => $letter->cash,
                'limit'             => $letter->limit,
                'admin_fees'        => $letter->admin_fees,
                'paid'              => $letter->paid,
                'remaining'         => $letter->remaining,
                'chassis_number'    => $letter->chassis_number,
                'engine_number'     => $letter->engine_number,
            ]);

            $letter->delete();
        });

        return back()->with('success', 'تم عمل المرتجع بنجاح');
    }
}
