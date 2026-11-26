<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-11-27: a removed bot with customer chats is archived and keeps its employee, so the
 * unique staff_id kept that employee (Manga) from getting a new bot. One live bot per employee
 * is now checked by the form, archived bots don't count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_bots', function (Blueprint $table) {
            $table->index('staff_id', 'whatsapp_bots_staff_id_index'); // the foreign key keeps an index
            $table->dropUnique('whatsapp_bots_staff_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_bots', function (Blueprint $table) {
            $table->unique('staff_id', 'whatsapp_bots_staff_id_unique');
            $table->dropIndex('whatsapp_bots_staff_id_index');
        });
    }
};
