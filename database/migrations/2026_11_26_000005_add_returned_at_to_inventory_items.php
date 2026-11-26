<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-11-26: a return deletes the letter and puts the machine back as a new row, but the
 * sold row stayed «اتباعت» - so a returned sale still counted as a sale. returned_at marks it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->timestamp('returned_at')->nullable()->after('sold_at');
        });

        // returns made before: a sold row with no letter left and a newer row of the same machine
        $returned = DB::table('inventory_items as o')
            ->join('inventory_items as n', fn ($j) => $j->on('n.chassis_number', '=', 'o.chassis_number')
                ->on('n.engine_number', '=', 'o.engine_number')->on('n.id', '>', 'o.id'))
            ->whereNotNull('o.sold_at')
            ->whereNotExists(fn ($q) => $q->from('letters')->whereColumn('letters.inventory_item_id', 'o.id'))
            ->groupBy('o.id')
            ->selectRaw('o.id, MIN(n.created_at) as returned_at')
            ->get();

        foreach ($returned as $row) {
            DB::table('inventory_items')->where('id', $row->id)->update(['returned_at' => $row->returned_at]);
        }
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('returned_at');
        });
    }
};
