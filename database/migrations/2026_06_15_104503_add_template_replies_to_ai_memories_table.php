<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_memories', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_memories', 'template_replies')) {
                $table->json('template_replies')->nullable()->after('content');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_memories', function (Blueprint $table) {
            if (Schema::hasColumn('ai_memories', 'template_replies')) {
                $table->dropColumn('template_replies');
            }
        });
    }
};
