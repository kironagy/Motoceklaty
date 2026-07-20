<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gemini_alerts', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable()->after('sent_at');
            $table->string('acknowledged_by')->nullable()->after('acknowledged_at');
        });
    }

    public function down(): void
    {
        Schema::table('gemini_alerts', function (Blueprint $table) {
            $table->dropColumn(['acknowledged_at', 'acknowledged_by']);
        });
    }
};
