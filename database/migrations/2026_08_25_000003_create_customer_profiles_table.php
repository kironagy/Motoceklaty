<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan task 3.5: what we know about a customer has to outlive the
 * conversation row it was learned in.
 *
 * Everything the bot learns today lives in whatsapp_conversations
 * .context_payload, so a customer who comes back next week is a stranger
 * again - asked their job and their budget from scratch even though they
 * completed half an application before. Keyed by phone, because that is the
 * only identifier that survives a new conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique();
            $table->string('name')->nullable();
            $table->string('job_type')->nullable();
            $table->string('income_category')->nullable();
            $table->foreignId('last_machine_id')->nullable()->constrained('machines')->nullOnDelete();
            $table->unsignedSmallInteger('preferred_months')->nullable();
            $table->decimal('last_deposit', 12, 2)->nullable();
            $table->unsignedInteger('applications_count')->default(0);
            $table->timestamp('last_application_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
    }
};
