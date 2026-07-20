<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
  public function up(): void
{
    Schema::table('whatsapp_bots', function (Blueprint $table) {
        $table->longText('qr_code')->nullable()->after('whatsapp_phone_number_id');
        $table->string('session_status')->nullable()->after('qr_code');
        $table->timestamp('connected_at')->nullable()->after('session_status');
    });
}

public function down(): void
{
    Schema::table('whatsapp_bots', function (Blueprint $table) {
        $table->dropColumn([
            'qr_code',
            'session_status',
            'connected_at',
        ]);
    });
}
};
