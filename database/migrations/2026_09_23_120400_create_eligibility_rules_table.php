<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eligibility_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_type_id')->nullable()->constrained()->cascadeOnDelete(); // null = all types
            $table->string('rule_type'); // keyed into the evaluator registry, e.g. age_range
            $table->json('params');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eligibility_rules');
    }
};
