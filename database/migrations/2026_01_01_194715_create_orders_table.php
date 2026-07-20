<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->string('name'); // اسم الطلبية

            $table->foreignId('trader_id')->constrained('traders')->cascadeOnDelete(); // التاجر
            $table->foreignId('stock_group_id')->constrained('stock_groups')->cascadeOnDelete(); // المجموعة

            $table->enum('type', ['import', 'export']); // استيراد/تصدير

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

