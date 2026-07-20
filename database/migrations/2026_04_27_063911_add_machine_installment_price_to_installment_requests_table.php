<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up(): void
{
    Schema::table('installment_requests', function (Blueprint $table) {
        $table->decimal('machine_installment_price', 10, 2)->nullable()->after('machine_id');
    });
}

public function down(): void
{
    Schema::table('installment_requests', function (Blueprint $table) {
        $table->dropColumn('machine_installment_price');
    });
}
};
