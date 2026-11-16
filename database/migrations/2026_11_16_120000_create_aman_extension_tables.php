<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Aman uploader Chrome extension (aman-extension/): sign-in tokens,
 * the taught recipes (one per request type), and one row per upload attempt.
 * Uploading never changes installment_requests itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One row per device an employee signed in from (email + password in the extension).
        Schema::create('aman_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->index();
            $table->string('token_hash', 64)->unique();
            $table->string('device', 120)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('aman_recipes', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40)->unique();
            $table->unsignedInteger('version')->default(1);
            $table->json('steps');
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('aman_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installment_request_id')->index();
            $table->foreignId('staff_id')->index();
            $table->string('type', 40)->nullable();
            $table->unsignedInteger('recipe_version')->nullable();
            // claimed | uploaded | rejected_before | error | released
            $table->string('status', 30)->default('claimed')->index();
            $table->string('aman_ref')->nullable();
            $table->text('message')->nullable();
            $table->json('log')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aman_uploads');
        Schema::dropIfExists('aman_recipes');
        Schema::dropIfExists('aman_tokens');
    }
};
