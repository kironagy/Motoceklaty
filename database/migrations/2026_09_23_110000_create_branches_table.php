<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('governorate'); // key from config('agent.governorates')
            $table->string('city');
            $table->string('address');
            $table->string('map_url')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('phones')->nullable();
            $table->json('working_hours')->nullable();
            $table->json('services')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'governorate']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
