<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            // ✅ بطاقات العميل والضامن
            $table->string('applicant_id_front')->nullable()->after('phone');
            $table->string('applicant_id_back')->nullable()->after('applicant_id_front');
            $table->string('guarantor_id_front')->nullable()->after('applicant_id_back');
            $table->string('guarantor_id_back')->nullable()->after('guarantor_id_front');

            // ✅ الحالة الوظيفية
            $table->enum('work_status', ['employee', 'pension', 'self_employed'])
                ->nullable()
                ->after('guarantor_id_back');

            // ✅ مستندات إضافية حسب الحالة
            $table->string('salary_slip_file')->nullable()->after('work_status');           // موظف
            $table->string('pension_statement_file')->nullable()->after('salary_slip_file'); // معاش
            $table->string('commercial_reg_file')->nullable()->after('pension_statement_file'); // سجل تجاري
            $table->string('tax_card_file')->nullable()->after('commercial_reg_file');      // بطاقة ضريبية
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn([
                'applicant_id_front',
                'applicant_id_back',
                'guarantor_id_front',
                'guarantor_id_back',
                'work_status',
                'salary_slip_file',
                'pension_statement_file',
                'commercial_reg_file',
                'tax_card_file',
            ]);
        });
    }
};
