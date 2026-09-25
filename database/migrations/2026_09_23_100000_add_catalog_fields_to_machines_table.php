<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DEC-19: cc, category, description, specifications, plus availability
 * (already decided) and is_active. Additive only - existing 58 machines
 * keep working; new columns default to safe/neutral values, never
 * fabricated data (DEC-19's own rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            if (! Schema::hasColumn('machines', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }

            if (! Schema::hasColumn('machines', 'availability')) {
                $table->string('availability')->default('in_stock');
            }

            if (! Schema::hasColumn('machines', 'cc')) {
                $table->unsignedInteger('cc')->nullable();
            }

            if (! Schema::hasColumn('machines', 'category')) {
                $table->string('category')->nullable();
            }

            if (! Schema::hasColumn('machines', 'description')) {
                $table->text('description')->nullable();
            }

            if (! Schema::hasColumn('machines', 'specifications')) {
                $table->json('specifications')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            foreach (['is_active', 'availability', 'cc', 'category', 'description', 'specifications'] as $column) {
                if (Schema::hasColumn('machines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
