<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('installment_requests', function (Blueprint $table) {
            $table->id();

            // 🔹 بيانات المكنة
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('installment_type');
            $table->unsignedInteger('months');

            // 🔹 بيانات العميل
            $table->string('applicant_name');
            $table->string('applicant_phone', 30);
            $table->string('applicant_national_id', 20)->nullable();
            $table->string('applicant_id_image')->nullable();
            $table->date('applicant_birthdate')->nullable();
            $table->boolean('applicant_age_ok')->default(false);

            // 🔹 بيانات الضامن
            $table->string('guarantor_name')->nullable();
            $table->string('guarantor_national_id', 20)->nullable();
            $table->string('guarantor_id_image')->nullable();
            $table->date('guarantor_birthdate')->nullable();
            $table->boolean('guarantor_age_ok')->default(false);

            // 🔹 الحالة الوظيفية
            $table->enum('work_status', ['employee', 'pension', 'self_employed']);

            // 🔹 موظف
            $table->unsignedInteger('salary_amount')->nullable();
            $table->date('salary_issue_date')->nullable();
            $table->string('salary_slip_file')->nullable();

            // 🔹 معاش
            $table->unsignedInteger('pension_amount')->nullable();

            // 🔹 صاحب نشاط
            $table->string('commercial_reg_file')->nullable();
            $table->date('commercial_reg_expiry')->nullable();
            $table->string('tax_card_file')->nullable();
            $table->date('tax_card_expiry')->nullable();
            $table->string('place_video')->nullable();

            // 🔹 الحالة والتحقق
            $table->enum('status', ['pending', 'needs_more_info', 'approved', 'rejected'])->default('pending');
            $table->json('checks_report')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_requests');
    }
};
