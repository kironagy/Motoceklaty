<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            \DB::statement("ALTER TABLE installment_requests MODIFY work_status 
                ENUM('employee', 'pension', 'self_employed', 'no_income_proof') 
                DEFAULT 'employee'");
        });
    }

    public function down(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            \DB::statement("ALTER TABLE installment_requests MODIFY work_status 
                ENUM('employee', 'pension', 'self_employed')");
        });
    }
};

