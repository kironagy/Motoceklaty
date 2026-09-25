<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_memories', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('category')->nullable();
            $table->string('title');
            $table->text('content');
            $table->integer('priority')->default(0);
            $table->boolean('is_pinned')->default(false);
            $table->json('scope_customer_types')->nullable();
            $table->json('scope_application_statuses')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'is_pinned']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_memories');
    }
};
