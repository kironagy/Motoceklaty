<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_style_memories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('source_bot_id')
                ->nullable()
                ->constrained('whatsapp_bots')
                ->nullOnDelete();

            $table->longText('summary')->nullable();

            $table->timestamps();

            $table->index('source_bot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_style_memories');
    }
};
