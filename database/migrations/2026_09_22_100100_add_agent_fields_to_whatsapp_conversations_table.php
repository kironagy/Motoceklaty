<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_conversations', 'customer_id')) {
                $table->foreignId('customer_id')->nullable()->after('whatsapp_bot_id')->constrained()->nullOnDelete();
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'state')) {
                $table->json('state')->nullable();
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'summary')) {
                $table->text('summary')->nullable();
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'summary_until_message_id')) {
                $table->unsignedBigInteger('summary_until_message_id')->nullable();
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'summary_updated_at')) {
                $table->timestamp('summary_updated_at')->nullable();
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'last_inbound_at')) {
                $table->timestamp('last_inbound_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            if (Schema::hasColumn('whatsapp_conversations', 'customer_id')) {
                $table->dropConstrainedForeignId('customer_id');
            }

            foreach (['state', 'summary', 'summary_until_message_id', 'summary_updated_at', 'last_inbound_at'] as $column) {
                if (Schema::hasColumn('whatsapp_conversations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
