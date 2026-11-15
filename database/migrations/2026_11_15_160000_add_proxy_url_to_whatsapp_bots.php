<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Owner 2026-11-15: each number's own proxy (stored encrypted - it holds the proxy password). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_bots', function (Blueprint $table) {
            $table->text('proxy_url')->nullable()->after('whatsapp_phone_number_id');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_bots', function (Blueprint $table) {
            $table->dropColumn('proxy_url');
        });
    }
};
