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
        $table->foreignId('status_updated_by')->nullable()->constrained('staff')->nullOnDelete();
        $table->timestamp('status_updated_at')->nullable();
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
