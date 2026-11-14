<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-11-15: the Automoon gateway was dropped for good (it could not reach @lid customers);
 * every number is back on our own worker.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('whatsapp_bots', 'automoon_device_id')) {
            Schema::table('whatsapp_bots', function (Blueprint $table) {
                $table->dropUnique(['automoon_device_id']);
                $table->dropColumn('automoon_device_id');
            });
        }
    }

    public function down(): void
    {
        // not restored: the gateway is gone
    }
};
