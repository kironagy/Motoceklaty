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
        $table->json('free_income_proof_images')->nullable()->after('free_work_address');
    });
}

public function down(): void
{
    Schema::table('installment_requests', function (Blueprint $table) {
        $table->dropColumn('free_income_proof_images');
    });
}

};
