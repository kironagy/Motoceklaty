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
        Schema::table('installment_systems', function (Blueprint $table) {
            $table->json('plans')->nullable();
            $table->dropColumn(['interest_12', 'interest_24', 'interest_36']);
        });
    }

    public function down(): void
    {
        Schema::table('installment_systems', function (Blueprint $table) {
            $table->decimal('interest_12', 5, 2)->nullable();
            $table->decimal('interest_24', 5, 2)->nullable();
            $table->decimal('interest_36', 5, 2)->nullable();
            $table->dropColumn('plans');
        });
    }
};
