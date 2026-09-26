<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teaching_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained('whatsapp_conversations')->nullOnDelete();
            $table->unsignedBigInteger('target_message_id')->nullable();
            $table->unsignedBigInteger('after_message_id')->nullable();
            $table->text('owner_text');
            $table->text('understanding')->nullable();
            $table->text('question')->nullable();
            $table->json('result')->nullable();
            $table->string('status', 20)->default('done');
            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bot_lessons', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('rule');
            $table->json('fixed_facts')->nullable();
            $table->text('example_context')->nullable();
            $table->text('example_reply')->nullable();
            $table->json('scope_customer_types')->nullable();
            $table->string('scope_stage', 40)->nullable();
            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('teaching_session_id')->nullable()->constrained('teaching_sessions')->nullOnDelete();
            $table->unsignedBigInteger('source_message_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('teaching_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teaching_session_id')->nullable()->constrained('teaching_sessions')->nullOnDelete();
            $table->foreignId('bot_lesson_id')->nullable()->constrained('bot_lessons')->nullOnDelete();
            $table->json('history');
            $table->text('bad_reply')->nullable();
            $table->text('expectation');
            $table->json('must_contain')->nullable();
            $table->string('scope_stage', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('last_result', 10)->nullable();
            $table->text('last_reply')->nullable();
            $table->text('last_reason')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('teaching_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teaching_session_id')->nullable()->constrained('teaching_sessions')->nullOnDelete();
            $table->string('kind', 30);
            $table->string('target_type', 40);
            $table->string('target_id')->nullable();
            $table->text('summary')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('status', 20)->default('proposed');
            $table->text('error')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teaching_changes');
        Schema::dropIfExists('teaching_cases');
        Schema::dropIfExists('bot_lessons');
        Schema::dropIfExists('teaching_sessions');
    }
};
