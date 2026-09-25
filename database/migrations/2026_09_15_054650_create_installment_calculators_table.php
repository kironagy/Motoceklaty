<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_calculators', function (Blueprint $table) {
            $table->id();

            $table->foreignId('brand_id')
                ->constrained('brands')
                ->cascadeOnDelete();

            $table->foreignId('machine_id')
                ->constrained('machines')
                ->cascadeOnDelete();

            $table->foreignId('installment_system_id')
                ->constrained('installment_systems')
                ->cascadeOnDelete();

            $table->integer('months');

            $table->decimal('down_payment', 12, 2)->default(0);

            $table->decimal('machine_cash_price', 12, 2)->default(0);

            $table->decimal('machine_installment_price', 12, 2)->default(0);

            $table->decimal('interest_percent', 8, 2)->default(0);

            $table->decimal('admin_fees_percent', 8, 2)->default(0);

            $table->decimal('admin_fees_amount', 12, 2)->default(0);

            $table->decimal('total_with_interest', 12, 2)->default(0);

            $table->decimal('monthly_installment', 12, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_calculators');
    }
};
