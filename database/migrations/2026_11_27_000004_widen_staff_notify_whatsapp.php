<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Owner 2026-11-27: the reports go to more than one number («على رقمين») - a comma list. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->string('notify_whatsapp', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->string('notify_whatsapp', 20)->nullable()->change();
        });
    }
};
