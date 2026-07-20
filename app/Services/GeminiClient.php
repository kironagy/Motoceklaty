<?php

namespace App\Services;

use App\Models\GeminiApiKeyModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiClient
{
    public function generateText(string $prompt, ?string $preferredModelCode = 'gemini-3.1-flash-lite', array $options = []): array
    {
        $manager = app(GeminiKeyManager::class);

        $failed429 = [];
        $triedIds = [];

        $modelCode = $preferredModelCode ?: 'gemini-3.1-flash-lite';
        $estimatedTokens = mb_strlen($prompt);

        while (true) {
            $modelRow = $this->getNextAvailableModel(
                preferredModelCode: $modelCode,
                estimatedTokens: $estimatedTokens,
                triedIds: $triedIds,
                embedding: false
            );

            if (! $modelRow) {
                if (! empty($failed429)) {
                    app(GeminiAlertService::class)->sendAllKeysExhaustedAlert($failed429);
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

                    $usedTokens = (int) data_get(
                        $json,
                        'usageMetadata.totalTokenCount',
                        $estimatedTokens
                    );

                    $manager->markUsed($modelRow, $usedTokens);

                    return [
                        'ok' => true,
                        'reply' => $reply,
                        'key_id' => $modelRow->gemini_api_key_id,
                        'model_id' => $modelRow->id,
                        'model' => $modelRow->model_code,
                    ];
                }

                $body = $response->body();
                $status = $response->status();

                if ($status === 400 && str_contains($body, 'API_KEY_INVALID')) {
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

                if ($status === 429 || $this->isQuotaError($body)) {
                    $manager->markRateLimited($modelRow, $body);

                    $failed429[] = [
                        'key_id' => $modelRow->gemini_api_key_id,
                        'model_id' => $modelRow->id,
                        'model' => $modelRow->model_code,
                        'error' => $body,
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

                    Log::warning('Gemini temporary server error, trying next key for same model', [
                        'key_id' => $modelRow->gemini_api_key_id,
                        'model' => $modelRow->model_code,
                        'status' => $status,
                        'body' => $body,
                    ]);

                    continue;
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

                Log::error('Gemini request exception', [
                    'key_id' => $modelRow->gemini_api_key_id,
                    'model' => $modelRow->model_code,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'key_id' => $modelRow->gemini_api_key_id,
                    'model' => $modelRow->model_code,
                ];
            }
        }
    }

    private function getNextAvailableModel(
        string $preferredModelCode,
        int $estimatedTokens,
        array $triedIds,
        ?bool $embedding = null
    ): ?GeminiApiKeyModel {
        app(GeminiKeyManager::class)->refreshWindows();

        return GeminiApiKeyModel::query()
            ->with('apiKey')
            ->whereNotIn('id', $triedIds)
            ->where('is_active', true)
            ->where('model_code', $preferredModelCode)
            ->whereColumn('requests_today', '<', 'rpd_limit')
            ->whereColumn('requests_this_minute', '<', 'rpm_limit')
            ->whereRaw('(tokens_this_second + ?) < tps_limit', [$estimatedTokens])
            ->where(function ($q) {
                $q->whereNull('cooldown_until')
                    ->orWhere('cooldown_until', '<=', now());
            })
            ->whereHas('apiKey', function ($q) {
                $q->where('is_active', true);
            })
            ->when(! is_null($embedding), function ($q) use ($embedding) {
                $q->where('is_embedding', $embedding);
            })
            ->orderBy('priority')
            ->orderBy('requests_today')
            ->orderBy('requests_this_minute')
            ->first();
    }

    private function isQuotaError(string $body): bool
    {
        $body = mb_strtolower($body);

        return str_contains($body, 'resource_exhausted')
            || str_contains($body, 'quota')
            || str_contains($body, 'rate limit')
            || str_contains($body, 'too many requests');
    }
}