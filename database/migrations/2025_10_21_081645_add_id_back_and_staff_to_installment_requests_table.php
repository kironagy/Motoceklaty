<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            // ✅ صور ضهر البطاقة
            $table->string('applicant_id_back_image')->nullable()->after('applicant_id_image');
            $table->string('guarantor_id_back_image')->nullable()->after('guarantor_id_image');

            // ✅ الموظف المسئول
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete()->after('place_video');
        });
    }

    public function down(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            $table->dropColumn(['applicant_id_back_image', 'guarantor_id_back_image']);
            $table->dropConstrainedForeignId('staff_id');
        });
    }
};
