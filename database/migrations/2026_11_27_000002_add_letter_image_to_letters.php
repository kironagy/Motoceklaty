<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Owner 2026-11-27: «صورة الجواب» - the licence letter's photo, sent to the customer when it is ready. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letters', function (Blueprint $table) {
            $table->string('letter_image')->nullable()->after('customer_id_image');
            $table->timestamp('ready_sent_at')->nullable()->after('letter_image');
        });
    }

    public function down(): void
    {
        Schema::table('letters', function (Blueprint $table) {
            $table->dropColumn(['letter_image', 'ready_sent_at']);
        });
    }
};
