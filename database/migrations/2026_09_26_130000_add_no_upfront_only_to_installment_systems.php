<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The showroom takes no down payment - only admin fees at pickup. A
 * customer who insists on paying nothing at all goes on a dearer system
 * with no fees (30% a year). That system is marked here and offered only
 * to that customer, never in the normal quote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_systems', function (Blueprint $table) {
            $table->boolean('no_upfront_only')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('installment_systems', function (Blueprint $table) {
            $table->dropColumn('no_upfront_only');
        });
    }
};
