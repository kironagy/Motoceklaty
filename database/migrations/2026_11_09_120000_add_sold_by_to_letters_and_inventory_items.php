<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employee who made a sale from the inventory page: the letter (and the sold piece)
 * carry his id so the sale shows under his name.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['letters', 'inventory_items'] as $table) {
            if (! Schema::hasColumn($table, 'sold_by')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->unsignedBigInteger('sold_by')->nullable()->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['letters', 'inventory_items'] as $table) {
            if (Schema::hasColumn($table, 'sold_by')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropIndex(['sold_by']);
                    $table->dropColumn('sold_by');
                });
            }
        }
    }
};
