<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            if (Schema::hasColumn('machines', 'color')) {
                $table->dropColumn('color');
            }

            if (Schema::hasColumn('machines', 'images')) {
                $table->dropColumn('images');
            }

            $table->json('colors')->nullable()->after('display_image');
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn('colors');
            $table->string('color')->nullable();
            $table->json('images')->nullable();
        });
    }
};
