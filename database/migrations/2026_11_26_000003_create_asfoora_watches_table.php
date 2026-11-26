<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-11-26: "ابعتلي تقرير عن الطلبات الجديدة كل ساعة لحد ما أقولك وقف" - a watch «عصفورة»
 * runs in the background and posts into the chat (with a push) until he stops it from the chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asfoora_watches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asfoora_chat_id')->index();
            $table->unsignedBigInteger('staff_id');
            $table->string('kind', 20); // new_requests | question
            $table->text('question')->nullable();
            $table->json('filters')->nullable();
            $table->unsignedInteger('every_minutes')->default(60);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asfoora_watches');
    }
};
