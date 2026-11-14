<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->decimal('days', 5, 2);
            // Days x the day salary at the time it was added, kept so a later raise doesn't change it.
            $table->decimal('amount', 10, 2);
            $table->date('deduction_date');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
            $table->index(['staff_id', 'deduction_date'], 'emp_deduction_staff_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_deductions');
    }
};
