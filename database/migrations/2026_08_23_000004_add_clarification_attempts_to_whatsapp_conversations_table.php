<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_conversations', 'clarification_attempts')) {
                $table->unsignedInteger('clarification_attempts')->default(0);
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'last_clarification_question')) {
                $table->string('last_clarification_question', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            foreach (['clarification_attempts', 'last_clarification_question'] as $column) {
                if (Schema::hasColumn('whatsapp_conversations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
