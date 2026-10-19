<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dynamic Links (spec 2026-10-18): the customer applies on a form the owner builds in the
 * dashboard, opened from a per-customer link the bot sends, instead of in the WhatsApp chat.
 *
 * - dynamic_forms / dynamic_form_versions: the builder's working copy and each published copy.
 *   A link stays on the version it was sent with.
 * - form_links: one link per customer conversation, with everything the follow-ups need.
 * - form_link_answers: autosaved per field, so a half-filled form is visible to staff.
 * - form_link_files: each upload and what the OCR/AI check said about it.
 * - message_media.message_id nullable: a form upload has no WhatsApp message.
 * - application_documents.status needs_review: a document the AI warned about still reaches staff.
 * - installment_requests.form_link_id: the request shows the form's extra answers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dynamic_forms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('schema')->nullable();          // the working copy the builder edits
            $table->boolean('is_bot_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->timestamps();
        });

        Schema::create('dynamic_form_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dynamic_form_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('schema');
            $table->foreignId('published_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
            $table->unique(['dynamic_form_id', 'version']);
        });

        Schema::create('form_links', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('dynamic_form_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('whatsapp_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('whatsapp_phone')->nullable();
            $table->string('created_by_type', 10)->default('bot');   // bot | staff
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->json('prefill')->nullable();
            // sent | opened | in_progress | submitted | expired | cancelled
            $table->string('status', 20)->default('sent')->index();
            // a link reopened after staff asked to fix data: the submit updates this request
            $table->string('purpose', 20)->default('apply');   // apply | fix
            $table->text('staff_note')->nullable();
            $table->unsignedBigInteger('renewed_from_id')->nullable();
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedTinyInteger('followups_sent')->default(0);
            $table->timestamp('last_followup_at')->nullable();
            $table->string('receipt_status', 10)->nullable();  // sent | failed
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('installment_request_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('form_link_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_link_id')->constrained()->cascadeOnDelete();
            $table->string('field_key');
            $table->text('value')->nullable();   // encrypted json (national ids, phones)
            $table->timestamps();
            $table->unique(['form_link_id', 'field_key']);
        });

        Schema::create('form_link_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_link_id')->constrained()->cascadeOnDelete();
            $table->string('field_key');
            $table->foreignId('media_id')->constrained('message_media')->cascadeOnDelete();
            // checking | ok | warning | failed (the check itself could not run)
            $table->string('check_status', 10)->default('checking');
            $table->string('detected_type_key')->nullable();
            $table->json('extracted')->nullable();
            $table->json('issues')->nullable();
            $table->text('message')->nullable();       // what the customer is told about this file
            $table->timestamps();
            $table->index(['form_link_id', 'field_key']);
        });

        Schema::table('message_media', function (Blueprint $table) {
            $table->unsignedBigInteger('message_id')->nullable()->change();
        });

        DB::statement("ALTER TABLE application_documents MODIFY status ENUM('processing','accepted','needs_review','rejected','failed','superseded') NOT NULL");

        Schema::table('installment_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('form_link_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('installment_requests', function (Blueprint $table) {
            $table->dropColumn('form_link_id');
        });

        DB::statement("UPDATE application_documents SET status = 'accepted' WHERE status = 'needs_review'");
        DB::statement("ALTER TABLE application_documents MODIFY status ENUM('processing','accepted','rejected','failed','superseded') NOT NULL");

        Schema::dropIfExists('form_link_files');
        Schema::dropIfExists('form_link_answers');
        Schema::dropIfExists('form_links');
        Schema::dropIfExists('dynamic_form_versions');
        Schema::dropIfExists('dynamic_forms');
    }
};
