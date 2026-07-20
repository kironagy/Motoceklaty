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
    Schema::table('installment_requests', function (Blueprint $table) {
        $table->string('price_offer_image')->nullable()->after('medical_card_image');
    });
}

public function down(): void
{
    Schema::table('installment_requests', function (Blueprint $table) {
        $table->dropColumn('price_offer_image');
    });
}

};
