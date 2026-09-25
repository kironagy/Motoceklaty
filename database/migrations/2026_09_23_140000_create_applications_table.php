<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** T13 §1: the new Application domain, separate from legacy `installment_requests` (T14 projects into that). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('origin_conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->foreignId('customer_type_id')->constrained();
            $table->foreignId('machine_id')->nullable()->constrained();
            $table->foreignId('installment_plan_id')->nullable()->constrained();
            $table->decimal('down_payment', 10, 2)->nullable();
            $table->enum('status', [
                'collecting', 'submitted', 'under_review', 'needs_more_info',
                'approved', 'rejected', 'withdrawn', 'expired',
            ])->default('collecting');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('installment_request_id')->nullable()->constrained('installment_requests');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
