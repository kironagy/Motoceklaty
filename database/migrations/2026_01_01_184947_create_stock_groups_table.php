<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_groups', function (Blueprint $table) {
            $table->id();

            $table->string('group_name');         // اسم المجموعة
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete(); // نوع المكنة من جدول machines

            $table->unsignedInteger('quantity')->default(0);          // الكمية اللي كتبتها
            $table->unsignedInteger('quantity_available')->default(0);// الكمية المتاحة (هتتحدث تلقائيًا)

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_groups');
    }
};

