<?php

// database/migrations/xxxx_xx_xx_create_attendances_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();

            $table->timestamp('checked_in_at');

            $table->boolean('is_late')->default(false);
            $table->unsignedInteger('penalty_days')->default(0);

            // اختياري: نخزن الـ rule اللي اتطبقت (للعرض في الجدول)
            $table->json('applied_rule')->nullable();

            $table->timestamps();

            $table->index(['staff_id', 'checked_in_at']);
            $table->index(['is_late', 'penalty_days']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};

