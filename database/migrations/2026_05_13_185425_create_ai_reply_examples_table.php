<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_reply_examples', function (Blueprint $table) {
            $table->id();

            $table->foreignId('source_bot_id')
                ->nullable()
                ->constrained('whatsapp_bots')
                ->nullOnDelete();

            $table->text('customer_message');
            $table->text('example_reply');

            $table->string('intent')->nullable();
            $table->json('keywords')->nullable();

            $table->boolean('active')->default(true);
            $table->unsignedInteger('usage_count')->default(0);

            $table->timestamps();

            $table->index('source_bot_id');
            $table->index('intent');
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_reply_examples');
    }
};
