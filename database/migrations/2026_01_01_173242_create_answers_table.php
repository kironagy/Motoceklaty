<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('answers', function (Blueprint $table) {
            $table->id();

            $table->string('name');                 // الاسم
            $table->string('phone');                // رقم التليفون
            $table->string('machine_name');         // اسم المكنة

            // صور
            $table->string('chassis_image')->nullable();    // صورة الشاسيه
            $table->string('engine_image')->nullable();     // صورة الماتور
            $table->string('id_front_image')->nullable();   // البطاقة وش
            $table->string('id_back_image')->nullable();    // البطاقة ضهر

            // ✅ حالتين (علامة صح/غلط)
            $table->boolean('received_from_raed')->default(false);   // تم استلام الجواب من رائد
            $table->boolean('delivered_to_customer')->default(false);// تم تسليم الجواب للعميل

            // المبلغ المتبقي (اختياري)
            $table->decimal('remaining_amount', 12, 2)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answers');
    }
};

