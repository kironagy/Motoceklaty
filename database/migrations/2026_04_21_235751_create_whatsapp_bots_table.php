<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_bots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('staff_id')
                ->unique()
                ->constrained('staff')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('whatsapp_phone_number')->nullable();
            $table->string('whatsapp_phone_number_id')->unique();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_bots');
    }
};
