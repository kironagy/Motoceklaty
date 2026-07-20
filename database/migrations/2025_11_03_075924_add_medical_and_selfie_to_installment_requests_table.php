<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            $table->string('medical_card_image')->nullable()->after('applicant_id_back_image');
            $table->string('selfie_image')->nullable()->after('medical_card_image');
        });
    }

    public function down(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            $table->dropColumn(['medical_card_image', 'selfie_image']);
        });
    }
};
