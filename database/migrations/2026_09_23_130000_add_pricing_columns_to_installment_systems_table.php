<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DEC-01 (agreed): pricing_mode replaces the "زيرو مصاريف" name-check on
 * the website (resources/views/layouts/app.blade.php ~L1160). Both existing
 * formulas keep their current behaviour - this only makes the branch
 * explicit and dashboard-editable instead of name-matched.
 *
 * minimum_down_payment is additive and nullable: DEC-01's minimum-down-
 * payment value was never given a number, so it stays unenforced (null)
 * until the owner sets it per system from the dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_systems', function (Blueprint $table) {
            $table->string('pricing_mode')->default('standard')->after('name');
            $table->decimal('minimum_down_payment', 10, 2)->nullable()->after('administrative_fees');
        });
    }

    public function down(): void
    {
        Schema::table('installment_systems', function (Blueprint $table) {
            $table->dropColumn(['pricing_mode', 'minimum_down_payment']);
        });
    }
};
