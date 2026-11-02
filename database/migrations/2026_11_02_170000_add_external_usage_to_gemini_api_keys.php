<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gemini_api_keys', function (Blueprint $table) {
            $table->json('usage_snapshot')->nullable()->after('last_error');
            $table->timestamp('usage_refreshed_at')->nullable()->after('usage_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('gemini_api_keys', function (Blueprint $table) {
            $table->dropColumn(['usage_snapshot', 'usage_refreshed_at']);
        });
    }
};
