<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evolves whatsapp_message_jobs into "turns" (T05). The table name stays;
 * new code treats a row as a turn (one open turn per conversation,
 * debounced - see DEC-07).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_message_jobs', function ($table) {
            if (! Schema::hasColumn('whatsapp_message_jobs', 'process_after')) {
                $table->timestamp('process_after')->nullable()->after('status');
            }

            if (! Schema::hasColumn('whatsapp_message_jobs', 'first_message_at')) {
                $table->timestamp('first_message_at')->nullable()->after('process_after');
            }

            if (! Schema::hasColumn('whatsapp_message_jobs', 'trace_id')) {
                $table->string('trace_id')->nullable()->after('first_message_at');
            }

            if (! Schema::hasColumn('whatsapp_message_jobs', 'superseded_by')) {
                $table->unsignedBigInteger('superseded_by')->nullable()->after('trace_id');
            }
        });

        DB::statement("ALTER TABLE whatsapp_message_jobs MODIFY status ENUM('pending','processing','generated','done','failed','superseded','skipped') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::table('whatsapp_message_jobs')->whereIn('status', ['superseded', 'skipped'])->update(['status' => 'failed']);
        DB::statement("ALTER TABLE whatsapp_message_jobs MODIFY status ENUM('pending','processing','generated','done','failed') NOT NULL DEFAULT 'pending'");

        Schema::table('whatsapp_message_jobs', function ($table) {
            foreach (['process_after', 'first_message_at', 'trace_id', 'superseded_by'] as $column) {
                if (Schema::hasColumn('whatsapp_message_jobs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
