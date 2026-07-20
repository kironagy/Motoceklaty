<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_conversations', 'last_machine_id')) {
                $table->unsignedBigInteger('last_machine_id')->nullable()->after('id');
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'last_machine_ids')) {
                $table->json('last_machine_ids')->nullable()->after('last_machine_id');
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'last_topic')) {
                $table->string('last_topic')->nullable()->after('last_machine_ids');
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'pending_question')) {
                $table->string('pending_question')->nullable()->after('last_topic');
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'context_payload')) {
                $table->json('context_payload')->nullable()->after('pending_question');
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            foreach ([
                'last_machine_id',
                'last_machine_ids',
                'last_topic',
                'pending_question',
                'context_payload',
            ] as $column) {
                if (Schema::hasColumn('whatsapp_conversations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
