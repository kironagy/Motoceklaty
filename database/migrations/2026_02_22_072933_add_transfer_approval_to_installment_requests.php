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
    $table->foreignId('pending_staff_id')->nullable()->after('staff_id');
    $table->foreignId('transfer_requested_by')->nullable();
    $table->timestamp('transfer_requested_at')->nullable();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            //
        });
    }
};
