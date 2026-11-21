<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-11-22: "مسح البوت" - a bot with customers is hidden and switched off, its customers,
// conversations and requests stay (deleting it would cascade to all of them).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_bots', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_bots', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
