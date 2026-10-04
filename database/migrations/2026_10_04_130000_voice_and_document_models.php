<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// QA 2026-10-04: voice notes on OpenAI transcription, documents on gpt-5-mini.
return new class extends Migration
{
    public function up(): void
    {
        // gpt-4o-mini-transcribe: audio input $3 / 1M tokens, text output $5 / 1M tokens
        DB::table('ai_model_prices')->updateOrInsert(['model_code' => 'gpt-4o-mini-transcribe'], [
            'input_per_million' => 3.00,
            'cached_per_million' => 0,
            'output_per_million' => 5.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // the document reader needs a gpt-5-mini row on every OpenAI key
        foreach (DB::table('gemini_api_keys')->where('provider', 'openai')->pluck('id') as $keyId) {
            if (! DB::table('gemini_api_key_models')->where('gemini_api_key_id', $keyId)->where('model_code', 'gpt-5-mini')->exists()) {
                $template = (array) DB::table('gemini_api_key_models')->where('gemini_api_key_id', $keyId)->first();

                if ($template !== []) {
                    unset($template['id']);
                    DB::table('gemini_api_key_models')->insert(array_merge($template, [
                        'model_code' => 'gpt-5-mini', 'is_active' => true, 'last_error' => null,
                        'created_at' => now(), 'updated_at' => now(),
                    ]));
                }
            }
        }

        DB::table('agent_settings')->updateOrInsert(['key' => 'document_model'], ['value' => json_encode('gpt-5-mini'), 'updated_at' => now(), 'created_at' => now()]);
    }

    public function down(): void
    {
        DB::table('ai_model_prices')->where('model_code', 'gpt-4o-mini-transcribe')->delete();
        DB::table('agent_settings')->where('key', 'document_model')->delete();
    }
};
