<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {

            if (!Schema::hasColumn('whatsapp_conversations', 'last_machine_ids')) {

                $table
                    ->json('last_machine_ids')
                    ->nullable()
                    ->after('last_machine_id');

            }

        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {

            if (Schema::hasColumn('whatsapp_conversations', 'last_machine_ids')) {

                $table->dropColumn('last_machine_ids');

            }

        });
    }
};
