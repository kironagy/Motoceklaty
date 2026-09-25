<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** T13 §1: structured application/guarantor data with provenance. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('field_key');
            $table->enum('party', ['applicant', 'guarantor'])->default('applicant');
            $table->text('value')->nullable();
            $table->enum('source', ['customer_stated', 'document', 'staff'])->default('customer_stated');
            $table->enum('status', ['valid', 'invalid', 'conflict'])->default('valid');
            $table->string('issue_code')->nullable();
            $table->foreignId('evidence_message_id')->nullable()->constrained('whatsapp_messages');
            // No FK yet: application_documents is created by T15, which runs after this
            // migration. T15 adds the constraint once that table exists.
            $table->unsignedBigInteger('document_id')->nullable();
            $table->timestamps();

            $table->unique(['application_id', 'party', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_data');
    }
};
