<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_bots', function (Blueprint $table) {
            $table->string('mode')->default('live')->after('staff_id');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_bots', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
