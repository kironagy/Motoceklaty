<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            $table->string('applicant_phone_2', 11)->nullable()->after('applicant_phone');
            $table->unique('applicant_phone_2');
        });
    }

    public function down(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            $table->dropUnique(['applicant_phone_2']);
            $table->dropColumn('applicant_phone_2');
        });
    }
};

