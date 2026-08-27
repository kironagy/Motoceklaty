<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits "generate the reply" from "send it" so a delivery failure never
 * replays the whole pipeline again (duplicate outgoing messages, re-OCR,
 * duplicate InstallmentRequest, inflated clarification/repeat counters -
 * see AI_WHATSAPP_BOT_MEMORY_INTELLIGENCE_AUDIT.md §16.1). 'generated'
 * means the reply/state mutation already happened and is durably stored in
 * `result`; only delivery is retried after that point.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_message_jobs') && ! Schema::hasColumn('whatsapp_message_jobs', 'result')) {
            Schema::table('whatsapp_message_jobs', function ($table) {
                $table->json('result')->nullable()->after('payload');
            });
        }

        if (Schema::hasTable('whatsapp_message_jobs')) {
            DB::statement("ALTER TABLE whatsapp_message_jobs MODIFY status ENUM('pending','processing','generated','done','failed') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('whatsapp_message_jobs') && Schema::hasColumn('whatsapp_message_jobs', 'result')) {
            Schema::table('whatsapp_message_jobs', function ($table) {
                $table->dropColumn('result');
            });
        }

        if (Schema::hasTable('whatsapp_message_jobs')) {
            DB::table('whatsapp_message_jobs')->where('status', 'generated')->update(['status' => 'pending']);
            DB::statement("ALTER TABLE whatsapp_message_jobs MODIFY status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending'");
        }
    }
};
