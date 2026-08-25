<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan task 3.3: give a memory a machine-readable half.
 *
 * Business rules currently live twice - as prose inside ai_memories.content
 * (what the model reads) and as PHP literals in ApplicationHandler (what the
 * code enforces). Changing one silently leaves the other behind. 'rules' is
 * the structured mirror of the same memory, so staff can edit an excluded
 * profession or a required document from Filament without a deploy, while
 * the prose keeps explaining it to the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_memories', function (Blueprint $table) {
            $table->json('rules')->nullable()->after('keywords');
        });
    }

    public function down(): void
    {
        Schema::table('ai_memories', function (Blueprint $table) {
            $table->dropColumn('rules');
        });
    }
};
