<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('stock_group_id')->constrained('stock_groups')->cascadeOnDelete();

            // بيانات المكنة في المخزن
            $table->string('color')->nullable();
            $table->string('chassis_image')->nullable();
            $table->string('engine_image')->nullable();

            // تظهر عند التعديل (بيانات العميل)
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('id_front_image')->nullable();
            $table->string('id_back_image')->nullable();
            $table->decimal('remaining_amount', 12, 2)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_items');
    }
};

