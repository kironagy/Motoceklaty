<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-11-14: replies go out through the Automoon gateway instead of our own WhatsApp worker
 * (WHATSAPP_GATEWAY=automoon). Each bot is one Automoon device; this is its deviceId.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_bots', function (Blueprint $table) {
            $table->string('automoon_device_id')->nullable()->unique()->after('whatsapp_phone_number_id');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_bots', function (Blueprint $table) {
            $table->dropUnique(['automoon_device_id']);
            $table->dropColumn('automoon_device_id');
        });
    }
};
