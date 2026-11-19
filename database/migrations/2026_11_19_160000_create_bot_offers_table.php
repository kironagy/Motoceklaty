<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-11-19: "عروض البوت" - offers the bot knows and works with until switched off.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_offers', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20); // machine | interest | general
            $table->string('title');
            $table->text('details')->nullable(); // what the bot reads about the offer
            $table->boolean('is_active')->default(true);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->integer('priority')->default(0);

            // machine: the offer owns one catalog machine (type = offer), kept in sync on save
            $table->foreignId('machine_id')->nullable()->constrained('machines')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('machine_name')->nullable();
            $table->string('condition', 30)->nullable(); // كسر زيرو | مستعمل | جديد
            $table->decimal('cash_price', 12, 2)->nullable();
            $table->decimal('installment_price', 12, 2)->nullable();
            $table->decimal('old_price', 12, 2)->nullable();
            $table->unsignedInteger('stock')->nullable();
            $table->json('images')->nullable();
            $table->json('specifications')->nullable();
            $table->json('aliases')->nullable();

            // interest: a yearly rate replacing the plan's for whom it applies (empty = everyone / every one)
            $table->decimal('yearly_rate', 6, 2)->nullable();
            $table->json('customer_type_ids')->nullable();
            $table->json('installment_system_ids')->nullable();
            $table->json('months')->nullable();
            $table->json('machine_ids')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_offers');
    }
};
