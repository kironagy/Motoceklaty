<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_type_id')->constrained()->cascadeOnDelete();
            $table->string('requirement_type'); // field|document
            $table->foreignId('requirement_field_id')->nullable()->constrained('requirement_fields')->cascadeOnDelete();
            $table->foreignId('document_type_id')->nullable()->constrained('document_types')->cascadeOnDelete();
            $table->boolean('is_required')->default(true);
            // {fact, op, value} - structured only, plan T11 §3. No free text.
            $table->json('condition')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_requirements');
    }
};
