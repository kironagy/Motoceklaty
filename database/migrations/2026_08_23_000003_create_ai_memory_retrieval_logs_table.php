<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_memory_retrieval_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_conversation_id')->nullable();
            $table->string('message_excerpt', 500)->nullable();
            $table->string('intent')->nullable();
            $table->json('candidate_memory_ids')->nullable();
            $table->json('selected_memory_ids')->nullable();
            $table->json('scores')->nullable();
            $table->string('retrieval_method')->nullable();
            $table->boolean('fell_back_to_full_dump')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_memory_retrieval_logs');
    }
};
