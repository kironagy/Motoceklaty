<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gemini_api_key_models', function (Blueprint $table) {
            $table->id();

            $table->foreignId('gemini_api_key_id')
                ->constrained('gemini_api_keys')
                ->cascadeOnDelete();

            $table->string('display_name');
            $table->string('model_code');

            $table->string('category')->nullable();

            $table->unsignedInteger('rpm_limit')->default(15);
            $table->unsignedInteger('rpd_limit')->default(1500);
            $table->unsignedBigInteger('tps_limit')->default(1000000);

            $table->unsignedInteger('requests_today')->default(0);
            $table->unsignedInteger('requests_this_minute')->default(0);
            $table->unsignedBigInteger('tokens_this_second')->default(0);

            $table->timestamp('minute_window_started_at')->nullable();
            $table->timestamp('second_window_started_at')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('cooldown_until')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_embedding')->default(false);

            $table->unsignedInteger('priority')->default(1);

            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->unique(['gemini_api_key_id', 'model_code'], 'gemini_key_model_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gemini_api_key_models');
    }
};
