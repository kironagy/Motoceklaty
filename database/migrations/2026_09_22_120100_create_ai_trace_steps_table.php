<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_trace_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trace_id')->constrained('ai_traces')->cascadeOnDelete();
            $table->unsignedBigInteger('turn_id')->nullable();
            $table->unsignedInteger('seq');
            $table->string('kind'); // model_call|tool_call
            $table->string('tool_name')->nullable();
            $table->string('permission')->nullable();
            $table->string('args_hash')->nullable();
            $table->json('args_redacted')->nullable();
            $table->json('result_redacted')->nullable();
            $table->string('result_code')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamps();

            $table->index(['turn_id', 'tool_name', 'args_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_trace_steps');
    }
};
