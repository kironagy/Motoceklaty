<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Owner 2026-11-26: «عصفورة» - the owner's chat with the AI about the system; its chats are kept. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asfoora_chats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_id')->index();
            $table->string('title')->nullable();
            $table->timestamps();
        });

        Schema::create('asfoora_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asfoora_chat_id')->index();
            $table->string('role', 16); // user | assistant
            $table->longText('content');
            $table->json('meta')->nullable(); // the tools it called, tokens, errors
            $table->timestamps();
        });

        // what the owner told her to keep in mind ("السكور الوحش ميت") - in every question after
        Schema::create('asfoora_memories', function (Blueprint $table) {
            $table->id();
            $table->text('content');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asfoora_memories');
        Schema::dropIfExists('asfoora_messages');
        Schema::dropIfExists('asfoora_chats');
    }
};
