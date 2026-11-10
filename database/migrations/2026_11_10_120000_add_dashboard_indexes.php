<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the queries every admin page and dashboard widget runs:
 * the bell's unread count, the request status counts, the monthly ranking,
 * and today's message / conversation counts. Each index is skipped when the
 * table or an index of the same name already exists, so the migration is safe
 * to run on a server that was partly indexed by hand.
 */
return new class extends Migration
{
    /** table => [index name => columns] */
    private const INDEXES = [
        'notifications' => [
            'notifications_user_read_index' => ['user_id', 'is_read'],
        ],
        'installment_requests' => [
            'installment_requests_status_index' => ['status'],
            'installment_requests_created_at_index' => ['created_at'],
            'installment_requests_staff_created_index' => ['staff_id', 'created_at'],
        ],
        'whatsapp_messages' => [
            'whatsapp_messages_created_direction_index' => ['created_at', 'direction'],
        ],
        'whatsapp_conversations' => [
            'whatsapp_conversations_updated_at_index' => ['updated_at'],
            'whatsapp_conversations_status_index' => ['status'],
        ],
        'staff_login_logs' => [
            'staff_login_logs_logged_in_at_index' => ['logged_in_at'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $name => $columns) {
                if (Schema::hasIndex($table, $name) || ! Schema::hasColumns($table, $columns)) {
                    continue;
                }

                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (array_keys($indexes) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
                }
            }
        }
    }
};
