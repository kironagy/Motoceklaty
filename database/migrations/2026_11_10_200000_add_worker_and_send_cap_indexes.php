<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server 2026-11-10: the worker polls whatsapp_message_jobs by status + process_after
 * every second, and the anti-ban send cap counts outgoing messages per bot per
 * hour/day; neither had a matching index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_message_jobs', function (Blueprint $table) {
            $table->index(['status', 'process_after'], 'wmj_status_process_after_idx');
        });
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->index(['whatsapp_bot_id', 'direction', 'delivery_status', 'created_at'], 'wm_bot_direction_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_message_jobs', fn (Blueprint $table) => $table->dropIndex('wmj_status_process_after_idx'));
        Schema::table('whatsapp_messages', fn (Blueprint $table) => $table->dropIndex('wm_bot_direction_status_created_idx'));
    }
};
