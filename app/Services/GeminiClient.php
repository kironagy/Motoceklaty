<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiClient
{
    public function generateText(string $prompt, ?string $preferredModelCode = 'gemini-3.1-flash-lite', array $options = []): array
    {
        $manager = app(GeminiKeyManager::class);

        $failed429 = [];
        $triedIds = [];
        $transientFailures = 0;
        $maxTransientFailovers = max(
            0,
            (int) ($options['maxTransientFailovers']
                ?? config('gemini.rate_limits.max_transient_failovers', 2))
        );

        $modelCode = $preferredModelCode ?: 'gemini-3.1-flash-lite';
        // 1 char = 1 token was ~3.7x too pessimistic in practice for this
        // Arabic-heavy content (measured via real usageMetadata.promptTokenCount
        // responses), causing tighter TPS-window rejections than necessary.
        $estimatedTokens = (int) ceil(mb_strlen($prompt) / 4);

        while (true) {
            $modelRow = $manager->reserveAvailableModel(
                preferredModelCode: $modelCode,
                estimatedTokens: $estimatedTokens,
                embedding: false,
                excludedIds: $triedIds,
                provider: 'gemini'
            );

            if (! $modelRow) {
                if (! empty($failed429)) {
                    app(GeminiAlertService::class)->startAllKeysExhaustedAlert($failed429);
                }

                return [
                    'ok' => false,
                    'error' => "موديل {$modelCode} غير متاح حاليًا أو كل مفاتيحه عليها ليمت.",
                    'failed_429_count' => count($failed429),
                    'model' => $modelCode,
                ];
            }

            $triedIds[] = $modelRow->id;

            try {
                $payload = [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => $options['temperature'] ?? 0.2,
                        'topP' => $options['topP'] ?? 0.4,
                        'topK' => $options['topK'] ?? 5,
                        'maxOutputTokens' => $options['maxOutputTokens'] ?? 260,
                    ],
                ];

                if (! empty($options['systemInstruction'])) {
                    $payload['systemInstruction'] = [
                        'parts' => [
                            ['text' => $options['systemInstruction']],
                        ],
                    ];
                }

                if (! empty($options['responseMimeType'])) {
                    $payload['generationConfig']['responseMimeType'] = $options['responseMimeType'];
                }

                if (! empty($options['responseSchema'])) {
                    $payload['generationConfig']['responseSchema'] = $options['responseSchema'];
                }

                $response = Http::timeout($options['timeout'] ?? 25)
                    ->post(
                        "https://generativelanguage.googleapis.com/v1beta/models/{$modelRow->model_code}:generateContent?key={$modelRow->apiKey->api_key}",
                        $payload
                    );

                if ($response->successful()) {
                    $json = $response->json();

                    $reply = data_get($json, 'candidates.0.content.parts.0.text');
                    $finishReason = data_get($json, 'candidates.0.finishReason');

                    if ($finishReason === 'MAX_TOKENS') {
                        Log::warning('Gemini reply truncated by maxOutputTokens', [
                            'model' => $modelRow->model_code,
                            'max_output_tokens' => $options['maxOutputTokens'] ?? null,
                            'reply_chars' => mb_strlen((string) $reply),
                        ]);
                    }

                    $usedTokens = (int) data_get(
                        $json,
                        'usageMetadata.totalTokenCount',
                        $estimatedTokens
                    );

                    $manager->markUsed($modelRow, $usedTokens);

                    return [
                        'ok' => true,
                        'reply' => $reply,
                        'truncated' => $finishReason === 'MAX_TOKENS',
                        'key_id' => $modelRow->gemini_api_key_id,
                        'model_id' => $modelRow->id,
                        'model' => $modelRow->model_code,
                    ];
                }

                $body = $response->body();
                $status = $response->status();

                if ($this->isInvalidApiKeyError($status, $body)) {
                    $modelRow->apiKey?->update([
                        'is_active' => false,
                        'last_error' => mb_substr($body, 0, 2000),
                    ]);

                    $modelRow->update([
                        'is_active' => false,
                        'last_error' => mb_substr($body, 0, 2000),
                    ]);

                    Log::warning('Gemini API key disabled because invalid', [
                        'key_id' => $modelRow->gemini_api_key_id,
                        'model_id' => $modelRow->id,
                        'model' => $modelRow->model_code,
                    ]);

                    continue;
                }

                if ($status === 403) {
                    $modelRow->update([
                        'is_active' => false,
                        'last_error' => mb_substr($body, 0, 2000),
                    ]);

                    Log::warning('Gemini model disabled for this key because permission was denied', [
                        'key_id' => $modelRow->gemini_api_key_id,
                        'model_id' => $modelRow->id,
                        'model' => $modelRow->model_code,
                    ]);

                    continue;
                }

                if ($status === 429 || $this->isQuotaError($body)) {
                    $rateLimit = app(GeminiRateLimitParser::class)->analyze(
                        $body,
                        $response->header('Retry-After')
                    );

                    $manager->markRateLimited(
                        model: $modelRow,
                        error: $body,
                        dailyLimit: $rateLimit['daily_limit'],
                        cooldownSeconds: $rateLimit['cooldown_seconds']
                    );

                    $failed429[] = [
                        'key_id' => $modelRow->gemini_api_key_id,
                        'model_id' => $modelRow->id,
                        'model' => $modelRow->model_code,
                        'daily_limit' => $rateLimit['daily_limit'],
                        'cooldown_seconds' => $rateLimit['cooldown_seconds'],
                    ];

                    continue;
                }

                if ($status === 404) {
                    $manager->markError($modelRow, $body, 86400);

                    $modelRow->update([
                        'is_active' => false,
                        'last_error' => mb_substr($body, 0, 2000),
                    ]);

                    Log::warning('Gemini model disabled because 404', [
                        'key_id' => $modelRow->gemini_api_key_id,
                        'model' => $modelRow->model_code,
                        'status' => $status,
                        'body' => $body,
                    ]);

                    continue;
                }

                if (in_array($status, [500, 502, 503, 504], true)) {
                    $manager->markError($modelRow, $body, 120);
                    $transientFailures++;

                    Log::warning('Gemini temporary server error, trying next key for same model', [
                        'key_id' => $modelRow->gemini_api_key_id,
                        'model' => $modelRow->model_code,
                        'status' => $status,
                        'body' => $body,
                    ]);

                    if ($transientFailures <= $maxTransientFailovers) {
                        continue;
                    }

                    return [
                        'ok' => false,
                        'error' => 'Gemini is temporarily unavailable.',
                        'status' => $status,
                        'key_id' => $modelRow->gemini_api_key_id,
                        'model' => $modelRow->model_code,
                    ];
                }

                $manager->markError($modelRow, $body, 120);

                Log::warning('Gemini non-retryable error', [
                    'key_id' => $modelRow->gemini_api_key_id,
                    'model' => $modelRow->model_code,
                    'status' => $status,
                    'body' => $body,
                ]);

                return [
                    'ok' => false,
                    'error' => $body,
                    'status' => $status,
                    'key_id' => $modelRow->gemini_api_key_id,
                    'model' => $modelRow->model_code,
                ];
            } catch (\Throwable $e) {
                $manager->markError($modelRow, $e->getMessage(), 120);
                $transientFailures++;

                Log::error('Gemini request exception', [
                    'key_id' => $modelRow->gemini_api_key_id,
                    'model' => $modelRow->model_code,
                    'error' => $e->getMessage(),
                ]);

                if ($transientFailures <= $maxTransientFailovers) {
                    continue;
                }

                return [
                    'ok' => false,
                    'error' => 'Gemini connection failed after retrying available keys.',
                    'key_id' => $modelRow->gemini_api_key_id,
                    'model' => $modelRow->model_code,
                ];
            }
        }
    }

    private function isQuotaError(string $body): bool
    {
        $body = mb_strtolower($body);

        return str_contains($body, 'resource_exhausted')
            || str_contains($body, 'quota')
            || str_contains($body, 'rate limit')
            || str_contains($body, 'too many requests');
    }

    private function isInvalidApiKeyError(int $status, string $body): bool
    {
        $body = mb_strtolower($body);

        return $status === 401
            || str_contains($body, 'api_key_invalid')
            || str_contains($body, 'api key not valid');
    }
}

