<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The agent now picks the best system for the customer by itself instead of
 * listing every system. These are the conditions it picks by, editable per
 * system from the dashboard. All empty = the system is open to everyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_systems', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('minimum_down_payment');
            $table->unsignedInteger('priority')->default(0)->after('is_active');
            $table->json('customer_type_ids')->nullable()->after('priority');
            $table->json('governorates')->nullable()->after('customer_type_ids');
            $table->decimal('max_financed_amount', 12, 2)->nullable()->after('governorates');
        });
    }

    public function down(): void
    {
        Schema::table('installment_systems', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'priority', 'customer_type_ids', 'governorates', 'max_financed_amount']);
        });
    }
};
