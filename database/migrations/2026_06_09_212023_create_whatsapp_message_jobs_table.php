<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_message_jobs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('whatsapp_bot_id')->nullable()->index();
            $table->unsignedBigInteger('whatsapp_conversation_id')->nullable()->index();

            $table->string('phone')->nullable()->index();
            $table->string('from')->nullable();
            $table->string('reply_jid')->nullable();

            $table->longText('message')->nullable();

            $table->json('media_items')->nullable();
            $table->json('payload')->nullable();

            $table->enum('status', [
                'pending',
                'processing',
                'done',
                'failed'
            ])->default('pending')->index();

            $table->unsignedInteger('attempts')->default(0);

            $table->timestamp('locked_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->longText('error')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_jobs');
    }
};
