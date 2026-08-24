<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Task Models
    |--------------------------------------------------------------------------
    |
    | 'reasoning' is used wherever we need real understanding or natural
    | phrasing (conversation planning, application extraction, the free-form
    | sales reply). 'fast' stays on the cheap lite model for high-volume,
    | low-judgement work. Everything used to run on the lite model, which is
    | why replies read mechanically and Egyptian slang was often missed.
    |
    | Both codes must exist in gemini_api_key_models for the key in use;
    | GeminiKeyManager falls back to another provisioned model on rate limits.
    |
    */

    'models' => [
        'reasoning' => env('GEMINI_REASONING_MODEL', 'gemini-3.7-flash'),
        'fast' => env('GEMINI_FAST_MODEL', 'gemini-3.1-flash-lite'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI-Written Phrasing (plan task 2.4)
    |--------------------------------------------------------------------------
    |
    | When enabled, AiReplyPhraser rewords an already-correct deterministic
    | reply so it reads like a person wrote it. The rewording is discarded
    | unless it carries exactly the same numbers, so prices and installments
    | can never drift. Set GEMINI_AI_PHRASING=false to fall back to the
    | fixed templates everywhere (one less Gemini call per money reply).
    |
    */

    'ai_phrasing' => [
        'enabled' => env('GEMINI_AI_PHRASING', true),
        'max_chars' => 1200,
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers Default Models
    |--------------------------------------------------------------------------
    */

    'providers' => [

        'gemini' => [
            'label' => 'Gemini',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'default_task' => 'sales_reply',

            // Kept in sync with the model codes actually provisioned in
            // gemini_api_key_models (provider=gemini) so that creating a new
            // Gemini API key via Filament (CreateGeminiApiKey) seeds the same
            // models already relied on at runtime (e.g. GeminiClient's default
            // 'gemini-3.1-flash-lite'). Update this list whenever a model is
            // added/retired in production.
            'default_models' => [

                [
                    'display_name' => 'Gemma 4 31B',
                    'model_code' => 'gemma-4-31b-it',
                    'category' => 'Other models',
                    'rpm_limit' => 15,
                    'rpd_limit' => 1500,
                    'tps_limit' => 1000000,
                    'priority' => 1,
                    'is_embedding' => false,
                ],

                [
                    'display_name' => 'Gemma 4 26B',
                    'model_code' => 'gemma-4-26b-a4b-it',
                    'category' => 'Other models',
                    'rpm_limit' => 15,
                    'rpd_limit' => 1500,
                    'tps_limit' => 1000000,
                    'priority' => 2,
                    'is_embedding' => false,
                ],

                [
                    'display_name' => 'Gemini Embedding 1',
                    'model_code' => 'gemini-embedding-001',
                    'category' => 'Other models',
                    'rpm_limit' => 15,
                    'rpd_limit' => 1000,
                    'tps_limit' => 1000000,
                    'priority' => 3,
                    'is_embedding' => true,
                ],

                [
                    'display_name' => 'Gemini Embedding 2',
                    'model_code' => 'gemini-embedding-2',
                    'category' => 'Other models',
                    'rpm_limit' => 15,
                    'rpd_limit' => 1000,
                    'tps_limit' => 1000000,
                    'priority' => 4,
                    'is_embedding' => true,
                ],

                [
                    'display_name' => 'Gemini 3.7 Flash',
                    'model_code' => 'gemini-3.7-flash',
                    'category' => 'Gemini',
                    'rpm_limit' => 10,
                    'rpd_limit' => 250,
                    'tps_limit' => 1000000,
                    'priority' => 0,
                    'is_embedding' => false,
                ],

                [
                    'display_name' => 'Gemini 3.1 Flash Lite',
                    'model_code' => 'gemini-3.1-flash-lite',
                    'category' => 'Gemini',
                    'rpm_limit' => 15,
                    'rpd_limit' => 500,
                    'tps_limit' => 1000000,
                    'priority' => 5,
                    'is_embedding' => false,
                ],
            ],
        ],

        'groq' => [
            'label' => 'Groq',
            'base_url' => 'https://api.groq.com/openai/v1',
            'default_task' => 'intent',

            'default_models' => [

 [
    'display_name' => 'Llama 3.1 8B Instant',
    'model_code' => 'llama-3.1-8b-instant',
    'category' => 'Intent / Extraction',
    'rpm_limit' => 30,
    'rpd_limit' => 14400,
    'tps_limit' => 500000,
    'priority' => 1,
    'is_embedding' => false,
],
[
    'display_name' => 'Qwen 3 32B',
    'model_code' => 'qwen/qwen3-32b',
    'category' => 'Extraction / Fallback',
    'rpm_limit' => 60,
    'rpd_limit' => 1000,
    'tps_limit' => 500000,
    'priority' => 2,
    'is_embedding' => false,
],
[
    'display_name' => 'Qwen 3.6 27B',
    'model_code' => 'qwen/qwen3.6-27b',
    'category' => 'Extraction / Fallback',
    'rpm_limit' => 30,
    'rpd_limit' => 1000,
    'tps_limit' => 200000,
    'priority' => 3,
    'is_embedding' => false,
],
[
    'display_name' => 'GPT OSS 20B',
    'model_code' => 'openai/gpt-oss-20b',
    'category' => 'Extraction / Fallback',
    'rpm_limit' => 30,
    'rpd_limit' => 1000,
    'tps_limit' => 200000,
    'priority' => 4,
    'is_embedding' => false,
],
[
    'display_name' => 'GPT OSS 120B',
    'model_code' => 'openai/gpt-oss-120b',
    'category' => 'Sales Reply',
    'rpm_limit' => 30,
    'rpd_limit' => 1000,
    'tps_limit' => 200000,
    'priority' => 5,
    'is_embedding' => false,
],
[
    'display_name' => 'Llama 3.3 70B Versatile',
    'model_code' => 'llama-3.3-70b-versatile',
    'category' => 'Sales Reply',
    'rpm_limit' => 30,
    'rpd_limit' => 1000,
    'tps_limit' => 100000,
    'priority' => 6,
    'is_embedding' => false,
],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Task Routing
    |--------------------------------------------------------------------------
    */

    'tasks' => [

        'intent' => [
            'preferred_provider' => 'groq',
            'fallback_provider' => 'gemini',
            'allow_embedding' => false,
        ],

        'extraction' => [
            'preferred_provider' => 'groq',
            'fallback_provider' => 'gemini',
            'allow_embedding' => false,
        ],

        'sales_reply' => [
            'preferred_provider' => 'gemini',
            'fallback_provider' => 'groq',
            'allow_embedding' => false,
        ],

        'recommendation' => [
            'preferred_provider' => 'gemini',
            'fallback_provider' => 'groq',
            'allow_embedding' => false,
        ],

        'embedding' => [
            'preferred_provider' => 'gemini',
            'fallback_provider' => null,
            'allow_embedding' => true,
        ],

        'guard' => [
            'preferred_provider' => 'groq',
            'fallback_provider' => null,
            'allow_embedding' => false,
        ],
    ],

    'rate_limits' => [
        'temporary_cooldown_seconds' => 60,
        'max_cooldown_seconds' => 3600,
        'max_transient_failovers' => 2,
        'daily_reset_timezone' => 'America/Los_Angeles',
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    */

    'alerts' => [
        'enabled' => true,

        'whatsapp_number' => env('GEMINI_ALERT_WHATSAPP_NUMBER'),

        'whatsapp_url' => env('WHATSAPP_SEND_MESSAGE_URL', env('WHATSAPP_WORKER_URL', 'http://127.0.0.1:3080') . '/send-message'),

        'repeat_every_minutes' => 5,

        'stop_words' => [
            'وقف',
            'تمام',
            'stop',
            'ok',
        ],
    ],
];
