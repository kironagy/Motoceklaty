<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requirement_fields', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('data_type'); // string|person_name|national_id|phone|date|money|integer|address|enum
            $table->json('enum_options')->nullable();
            $table->string('scope')->default('customer'); // customer|application|guarantor
            $table->boolean('is_sensitive')->default(false);
            $table->text('description_for_ai')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requirement_fields');
    }
};
