<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** T13 §1: customer-scope facts (survive across applications), with provenance. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('field_key');
            $table->text('value')->nullable();
            $table->enum('source', ['customer_stated', 'document', 'staff'])->default('customer_stated');
            $table->enum('status', ['valid', 'invalid', 'conflict'])->default('valid');
            $table->foreignId('evidence_message_id')->nullable()->constrained('whatsapp_messages');
            // No FK yet: application_documents is created by T15 (see application_data migration).
            $table->unsignedBigInteger('document_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_attributes');
    }
};
