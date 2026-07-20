<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            // 🧹 حذف الأعمدة القديمة لو موجودة
            $oldColumns = [
                'name', 'address', 'phone',
                'id_card_image', 'guarantor_card_image', 'documents'
            ];

            foreach ($oldColumns as $col) {
                if (Schema::hasColumn('deliveries', $col)) {
                    $table->dropColumn($col);
                }
            }

            // 🔹 بيانات المكنة
            if (!Schema::hasColumn('deliveries', 'machine_id')) {
                $table->foreignId('machine_id')->nullable()->constrained('machines')->nullOnDelete();
            }
            if (!Schema::hasColumn('deliveries', 'installment_type')) {
                $table->string('installment_type')->nullable();
            }
            if (!Schema::hasColumn('deliveries', 'months')) {
                $table->unsignedInteger('months')->nullable();
            }

            // 🔹 بيانات العميل
            if (!Schema::hasColumn('deliveries', 'applicant_name')) {
                $table->string('applicant_name')->nullable();
            }
            if (!Schema::hasColumn('deliveries', 'applicant_phone')) {
                $table->string('applicant_phone')->nullable();
            }
            if (!Schema::hasColumn('deliveries', 'applicant_address')) {
                $table->string('applicant_address')->nullable();
            }
            if (!Schema::hasColumn('deliveries', 'applicant_national_id')) {
                $table->string('applicant_national_id')->nullable();
            }
            if (!Schema::hasColumn('deliveries', 'applicant_id_image')) {
                $table->string('applicant_id_image')->nullable();
            }
            if (!Schema::hasColumn('deliveries', 'applicant_birthdate')) {
                $table->date('applicant_birthdate')->nullable();
            }
            if (!Schema::hasColumn('deliveries', 'applicant_age_ok')) {
                $table->boolean('applicant_age_ok')->default(false);
            }

            // 🔹 بيانات الضامن
            if (!Schema::hasColumn('deliveries', 'guarantor_name')) {
                $table->string('guarantor_name')->nullable();
            }
            if (!Schema::hasColumn('deliveries', 'guarantor_national_id')) {
                $table->string('guarantor_national_id')->nullable();
            }
            if (!Schema::hasColumn('deliveries', 'guarantor_id_image')) {
                $table->string('guarantor_id_image')->nullable();
            }
            if (!Schema::hasColumn('deliveries', 'guarantor_birthdate')) {
                $table->date('guarantor_birthdate')->nullable();
            }
            if (!Schema::hasColumn('deliveries', 'guarantor_age_ok')) {
                $table->boolean('guarantor_age_ok')->default(false);
            }

            // 🔹 الحالة الوظيفية
            if (!Schema::hasColumn('deliveries', 'work_status')) {
                $table->enum('work_status', ['employee', 'pension', 'self_employed'])->nullable();
            }

            // 🔹 موظف
            if (!Schema::hasColumn('deliveries', 'salary_amount')) {
                $table->decimal('salary_amount', 10, 2)->nullable();
            }
            if (!Schema::hasColumn('deliveries', 'salary_issue_date')) {
                $table->date('salary_issue_date')->nullable();
            }
            if (!Schema::hasColumn('deliveries', 'salary_slip_file')) {
                $table->string('salary_slip_file')->nullable();
            }

            // 🔹 معاش
            if (!Schema::hasColumn('deliveries', 'pension_amount')) {
                $table->decimal('pension_amount', 10, 2)->nullable();
            }

            // 🔹 صاحب نشاط
            if (!Schema::hasColumn('deliveries', 'commercial_reg_file')) {
                $table->string('commercial_reg_file')->nullable();
            }
            if (!Schema::hasColumn('deliveries', 'commercial_reg_expiry')) {
                $table->date('commercial_reg_expiry')->nullable();
            }
            if (!Schema::hasColumn('deliveries', 'tax_card_file')) {
                $table->string('tax_card_file')->nullable();
            }
            if (!Schema::hasColumn('deliveries', 'tax_card_expiry')) {
                $table->date('tax_card_expiry')->nullable();
            }
            if (!Schema::hasColumn('deliveries', 'place_video')) {
                $table->string('place_video')->nullable();
            }

            // 🔹 الحالة والتحقق
            if (!Schema::hasColumn('deliveries', 'status')) {
                $table->string('status')->default('pending');
            }
            if (!Schema::hasColumn('deliveries', 'checks_report')) {
                $table->json('checks_report')->nullable();
            }

            // 🔹 الموظف المرتبط
            if (!Schema::hasColumn('deliveries', 'staff_id')) {
                $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $cols = [
                'machine_id',
                'installment_type',
                'months',
                'applicant_name',
                'applicant_phone',
                'applicant_address',
                'applicant_national_id',
                'applicant_id_image',
                'applicant_birthdate',
                'applicant_age_ok',
                'guarantor_name',
                'guarantor_national_id',
                'guarantor_id_image',
                'guarantor_birthdate',
                'guarantor_age_ok',
                'work_status',
                'salary_amount',
                'salary_issue_date',
                'salary_slip_file',
                'pension_amount',
                'commercial_reg_file',
                'commercial_reg_expiry',
                'tax_card_file',
                'tax_card_expiry',
                'place_video',
                'status',
                'checks_report',
                'staff_id',
            ];

            foreach ($cols as $col) {
                if (Schema::hasColumn('deliveries', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
