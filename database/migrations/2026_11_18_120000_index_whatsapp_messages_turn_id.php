<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-11-18: turns are picked by how long the customer has waited and how many messages he
// sent (whatsapp:process-jobs), counted through whatsapp_messages.turn_id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->index('turn_id', 'whatsapp_messages_turn_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropIndex('whatsapp_messages_turn_id_index');
        });
    }
};
