<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_traces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->unsignedBigInteger('turn_id')->nullable();
            $table->string('prompt_version')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('status')->default('running'); // running|completed|failed|fallback|handoff
            $table->json('context_manifest')->nullable();
            $table->json('final_reply_message_ids')->nullable();
            $table->json('guard_events')->nullable();
            $table->string('error_code')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->index('turn_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_traces');
    }
};
