<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Owner 2026-11-27: «عصفورة»'s reports also reach him on WhatsApp - shows on a locked phone whatever the browser does. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->string('notify_whatsapp', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('notify_whatsapp');
        });
    }
};
