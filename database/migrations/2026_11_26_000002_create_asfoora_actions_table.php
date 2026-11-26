<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-11-26: «عصفورة» reads Aman's sheet and prepares the status changes of the showroom's
 * requests. Nothing changes until the owner presses «نفّذ»: the prepared changes wait here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asfoora_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asfoora_chat_id')->index();
            $table->string('kind', 40); // aman_statuses
            $table->longText('payload'); // the changes, as prepared
            $table->string('status', 16)->default('pending'); // pending | applied | cancelled
            $table->unsignedBigInteger('applied_by')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asfoora_actions');
    }
};
