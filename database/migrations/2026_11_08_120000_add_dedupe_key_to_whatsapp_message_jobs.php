<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One key per automated message (a follow-up, a nudge, a status notice):
 * a retry after a failed or timed-out send reuses the same turn and the
 * same outbound row, so the worker's client_id registry never sends it twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_message_jobs', function (Blueprint $table) {
            $table->string('dedupe_key', 191)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_message_jobs', function (Blueprint $table) {
            $table->dropUnique(['dedupe_key']);
            $table->dropColumn('dedupe_key');
        });
    }
};
