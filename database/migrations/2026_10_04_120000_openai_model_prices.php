<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Owner 2026-10-04: GPT keys. OpenAI prices per 1M tokens (input / cached / output).
return new class extends Migration
{
    private const PRICES = [
        'gpt-5-nano' => [0.05, 0.005, 0.40],
        'gpt-5-mini' => [0.25, 0.025, 2.00],
    ];

    public function up(): void
    {
        foreach (self::PRICES as $model => [$input, $cached, $output]) {
            DB::table('ai_model_prices')->updateOrInsert(['model_code' => $model], [
                'input_per_million' => $input,
                'cached_per_million' => $cached,
                'output_per_million' => $output,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('ai_model_prices')->whereIn('model_code', array_keys(self::PRICES))->delete();
    }
};
