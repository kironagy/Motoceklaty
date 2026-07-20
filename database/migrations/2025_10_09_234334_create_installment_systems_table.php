<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_systems', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('interest_12', 5, 2)->nullable();
            $table->decimal('interest_24', 5, 2)->nullable();
            $table->decimal('interest_36', 5, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_systems');
    }
};
