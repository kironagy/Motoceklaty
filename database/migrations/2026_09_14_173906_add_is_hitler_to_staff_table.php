<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('staff', 'is_hitler')) {
            Schema::table('staff', function (Blueprint $table) {
                $table->boolean('is_hitler')->default(false)->after('is_bot');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('staff', 'is_hitler')) {
            Schema::table('staff', function (Blueprint $table) {
                $table->dropColumn('is_hitler');
            });
        }
    }
};
