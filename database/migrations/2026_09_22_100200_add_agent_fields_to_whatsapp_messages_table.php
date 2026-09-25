<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_messages', 'whatsapp_bot_id')) {
                $table->unsignedBigInteger('whatsapp_bot_id')->nullable()->after('whatsapp_conversation_id');
            }

            if (! Schema::hasColumn('whatsapp_messages', 'sender_type')) {
                $table->string('sender_type')->nullable();
            }

            if (! Schema::hasColumn('whatsapp_messages', 'type')) {
                $table->string('type')->nullable();
            }

            if (! Schema::hasColumn('whatsapp_messages', 'text')) {
                $table->text('text')->nullable();
            }

            if (! Schema::hasColumn('whatsapp_messages', 'transcript')) {
                $table->text('transcript')->nullable();
            }

            if (! Schema::hasColumn('whatsapp_messages', 'transcription_status')) {
                // null = not applicable (no voice media). See DEC-08.
                $table->string('transcription_status')->nullable();
            }

            if (! Schema::hasColumn('whatsapp_messages', 'quoted_message_id')) {
                $table->unsignedBigInteger('quoted_message_id')->nullable();
            }

            if (! Schema::hasColumn('whatsapp_messages', 'turn_id')) {
                $table->unsignedBigInteger('turn_id')->nullable();
            }

            if (! Schema::hasColumn('whatsapp_messages', 'delivery_status')) {
                $table->string('delivery_status')->nullable();
            }

            if (! Schema::hasColumn('whatsapp_messages', 'metadata')) {
                $table->json('metadata')->nullable();
            }
        });

        // The plan requires a GLOBAL unique index on wa_message_id (it's
        // already unique per WhatsApp bot: "<botId>_<key.id>"). Never drop
        // rows here - abort loudly if duplicates already exist so an owner
        // can resolve them by hand first.
        $duplicates = DB::table('whatsapp_messages')
            ->select('wa_message_id')
            ->whereNotNull('wa_message_id')
            ->groupBy('wa_message_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('wa_message_id');

        if ($duplicates->isNotEmpty()) {
            throw new \RuntimeException(
                'Cannot add a global unique index on whatsapp_messages.wa_message_id: '
                .'duplicate values already exist for: '.$duplicates->implode(', ')
                .'. Resolve these rows by hand before re-running migrations.'
            );
        }

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->unique('wa_message_id', 'whatsapp_messages_wa_message_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropUnique('whatsapp_messages_wa_message_id_unique');

            foreach ([
                'whatsapp_bot_id', 'sender_type', 'type', 'text', 'transcript',
                'transcription_status', 'quoted_message_id', 'turn_id',
                'delivery_status', 'metadata',
            ] as $column) {
                if (Schema::hasColumn('whatsapp_messages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
