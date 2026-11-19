<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-11-19: the owner can lock the extension for an employee (كنترول الاكستنشن).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->boolean('aman_extension_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('aman_extension_enabled');
        });
    }
};
