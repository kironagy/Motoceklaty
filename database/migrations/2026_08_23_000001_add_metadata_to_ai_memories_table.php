<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_memories', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_memories', 'category')) {
                $table->string('category')->nullable()->after('content');
            }

            if (! Schema::hasColumn('ai_memories', 'scope')) {
                $table->string('scope')->nullable()->after('category');
            }

            if (! Schema::hasColumn('ai_memories', 'applicable_intents')) {
                $table->json('applicable_intents')->nullable()->after('scope');
            }

            if (! Schema::hasColumn('ai_memories', 'keywords')) {
                $table->json('keywords')->nullable()->after('applicable_intents');
            }

            if (! Schema::hasColumn('ai_memories', 'priority')) {
                $table->integer('priority')->default(0)->after('keywords');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_memories', function (Blueprint $table) {
            foreach (['category', 'scope', 'applicable_intents', 'keywords', 'priority'] as $column) {
                if (Schema::hasColumn('ai_memories', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
