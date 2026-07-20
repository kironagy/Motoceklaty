<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            if (!Schema::hasColumn('whatsapp_conversations', 'current_step')) {
                $table->string('current_step')->nullable()->after('last_machine_id');
            }

            if (!Schema::hasColumn('whatsapp_conversations', 'last_intent')) {
                $table->string('last_intent')->nullable()->after('current_step');
            }

            if (!Schema::hasColumn('whatsapp_conversations', 'customer_job_type')) {
                $table->string('customer_job_type')->nullable()->after('last_intent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropColumn(['current_step', 'last_intent', 'customer_job_type']);
        });
    }
};
