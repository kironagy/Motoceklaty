<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** T15 §1: the auditable record of every document processed for an application. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->nullable()->constrained();
            $table->foreignId('media_id')->constrained('message_media');
            $table->enum('party', ['applicant', 'guarantor'])->default('applicant');
            $table->enum('status', ['processing', 'accepted', 'rejected', 'failed', 'superseded']);
            $table->string('detected_type_key')->nullable();
            $table->string('expected_type_key')->nullable();
            $table->float('confidence')->nullable();
            $table->text('extracted')->nullable();
            $table->json('issues')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
        });

        // The two document_id columns predate this table (T13); add the
        // deferred FK now that application_documents exists.
        Schema::table('application_data', function (Blueprint $table) {
            $table->foreign('document_id')->references('id')->on('application_documents')->nullOnDelete();
        });

        Schema::table('customer_attributes', function (Blueprint $table) {
            $table->foreign('document_id')->references('id')->on('application_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('application_data', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
        });

        Schema::table('customer_attributes', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
        });

        Schema::dropIfExists('application_documents');
    }
};
