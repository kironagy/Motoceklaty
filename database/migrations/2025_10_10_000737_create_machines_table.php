<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('display_image')->nullable();
            $table->string('color')->nullable();
            $table->json('images')->nullable();
            $table->decimal('cash_price', 10, 2)->nullable();
            $table->decimal('installment_price', 10, 2)->nullable();
            $table->json('installment_systems')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machines');
    }
};
