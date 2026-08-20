<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('wa_message_id')->nullable()->after('whatsapp_conversation_id');
            $table->unique(['whatsapp_conversation_id', 'wa_message_id'], 'whatsapp_messages_conversation_wa_message_unique');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropUnique('whatsapp_messages_conversation_wa_message_unique');
            $table->dropColumn('wa_message_id');
        });
    }
};
