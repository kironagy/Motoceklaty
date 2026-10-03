<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-10-02: a paid Gemini key with $100 of credit. Every call is
 * logged with its tokens and its cost, so the dashboard can show what each
 * reply cost, what was spent in total and what is left.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_model_prices', function (Blueprint $table) {
            $table->id();
            $table->string('model_code')->unique();
            $table->decimal('input_per_million', 10, 4)->default(0);
            $table->decimal('cached_per_million', 10, 4)->default(0);
            $table->decimal('output_per_million', 10, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gemini_api_key_id')->nullable()->index();
            $table->string('model_code');
            $table->string('source', 30)->default('bot');
            $table->boolean('is_paid')->default(false);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cached_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('thoughts_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::table('gemini_api_keys', function (Blueprint $table) {
            $table->boolean('is_paid')->default(false)->after('is_active');
            $table->decimal('credit_usd', 10, 2)->nullable()->after('is_paid');
            $table->timestamp('credit_since')->nullable()->after('credit_usd');
        });

        // Google's paid-tier prices, USD per 1M tokens (ai.google.dev/pricing, 2026-10-02).
        $now = now();
        DB::table('ai_model_prices')->insert([
            ['model_code' => 'gemini-3.1-flash-lite', 'input_per_million' => 0.25, 'cached_per_million' => 0.025, 'output_per_million' => 1.50, 'created_at' => $now, 'updated_at' => $now],
            ['model_code' => 'gemini-3.5-flash-lite', 'input_per_million' => 0.30, 'cached_per_million' => 0.03, 'output_per_million' => 2.50, 'created_at' => $now, 'updated_at' => $now],
            ['model_code' => 'gemini-3.5-flash', 'input_per_million' => 1.50, 'cached_per_million' => 0.15, 'output_per_million' => 9.00, 'created_at' => $now, 'updated_at' => $now],
            ['model_code' => 'gemini-3.8-flash', 'input_per_million' => 0.75, 'cached_per_million' => 0.075, 'output_per_million' => 3.75, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::table('gemini_api_keys', function (Blueprint $table) {
            $table->dropColumn(['is_paid', 'credit_usd', 'credit_since']);
        });
        Schema::dropIfExists('ai_usage_logs');
        Schema::dropIfExists('ai_model_prices');
    }
};
