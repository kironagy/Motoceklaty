<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-11-15: WhatsApp's own warnings and temporary restrictions on a number (Baileys rc14),
 * shown on the bots page with when they end; the bot slows down or stops until then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_bots', function (Blueprint $table) {
            // first_warning | second_warning | capped | timelock - null when WhatsApp says nothing
            $table->string('wa_limit_level', 32)->nullable()->after('session_status');
            $table->timestamp('wa_limit_until')->nullable()->after('wa_limit_level');
            $table->timestamp('wa_limit_since')->nullable()->after('wa_limit_until');
            // what WhatsApp sent: reason, new-chat quota used / total
            $table->json('wa_limit_detail')->nullable()->after('wa_limit_since');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_bots', function (Blueprint $table) {
            $table->dropColumn(['wa_limit_level', 'wa_limit_until', 'wa_limit_since', 'wa_limit_detail']);
        });
    }
};
