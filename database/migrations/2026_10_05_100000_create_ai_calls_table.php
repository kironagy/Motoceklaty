<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuild OBS-001: one row per model HTTP attempt - which turn it served,
 * what it was for (main loop, understanding, reviewer, work, document...),
 * its tokens, latency and outcome. ai_usage_logs stays the cost ledger;
 * this is the per-turn trace. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_calls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trace_id')->nullable()->index();
            $table->unsignedBigInteger('turn_id')->nullable()->index();
            $table->unsignedBigInteger('conversation_id')->nullable()->index();
            $table->string('runner', 10)->nullable();
            $table->string('label', 30);
            $table->string('purpose', 30)->nullable();
            $table->string('provider', 20);
            $table->string('model', 80);
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cached_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('outcome', 30);
            $table->json('capture')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_calls');
    }
};
