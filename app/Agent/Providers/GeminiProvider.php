<?php

namespace App\Agent\Providers;

use App\Models\GeminiApiKeyModel;
use App\Services\GeminiAlertService;
use App\Services\GeminiKeyManager;
use App\Services\GeminiRateLimitParser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gemini implementation of AiProvider. Reuses GeminiKeyManager's atomic
 * reservation/cooldown pool for credential/project failover (DEC-10):
 * a 429/quota response fails over to the next key; a daily-quota response
 * marks the model unavailable until Google's reset; an invalid/revoked key
 * is disabled and never retried; a 5xx gets a bounded number of failovers
 * before giving up; an invalid request (4xx other than 401/403/404/429) is
 * never retried on another credential, since another credential would
 * reproduce the same error.
 */
class GeminiProvider implements AiProvider
{
    public function chat(AiRequest $request): AiResponse
    {
        $modelCode = config('agent.model');

        if (! $modelCode) {
            throw new \RuntimeException('config("agent.model") is not set (AGENT_MODEL). See DEC-06.');
        }

        // Live testing hit a flash-lite outage (Google 503 "high demand",
        // then every key in cooldown): customers only got the "busy" line.
        // A configured fallback model is tried only when the primary is
        // unavailable - never for a request the primary rejected.
        $fallbacks = array_values(array_filter(array_map('trim', explode(',', (string) config('agent.fallback_models')))));
        $models = array_values(array_unique(array_merge([$modelCode], $fallbacks)));

        // One budget for the whole call: a 30s timeout per key per model
        // stacked into several minutes while Google was overloaded.
        $deadline = microtime(true) + (int) config('agent.provider_budget_seconds', 45);

        foreach ($models as $index => $model) {
            try {
                return $this->chatWith($request, $model, isFallback: $index > 0, deadline: $deadline);
            } catch (AiProviderException $e) {
                if (! $e->retryable || $index === count($models) - 1) {
                    throw $e;
                }

                Log::warning('Gemini primary model unavailable, using fallback model', ['from' => $model, 'error' => $e->getMessage()]);
            }
        }

        throw new AiProviderException('No Gemini model configured.', retryable: true);
    }

    private function chatWith(AiRequest $request, string $modelCode, bool $isFallback, float $deadline = INF): AiResponse
    {
        $manager = app(GeminiKeyManager::class);
        $maxTransientFailovers = max(0, (int) config('gemini.rate_limits.max_transient_failovers', 2));
        $estimatedTokens = $this->estimateTokens($request);
        $payload = $this->buildPayload($request);

        if ($isFallback && $request->thinkingLevel === null) {
            // The fallback (gemini-3.5-flash-lite) rejects thinkingBudget and
            // takes 13-17s with its default thinking; "minimal" measured 3-9s.
            $payload['generationConfig']['thinkingConfig'] = ['thinkingLevel' => (string) config('agent.fallback_thinking_level', 'minimal')];
        }

        $triedIds = [];
        $transientFailures = 0;
        $start = microtime(true);

        while (true) {
            $remaining = $deadline - microtime(true);

            if ($remaining < 3) {
                throw new AiProviderException('Gemini call budget exhausted.', retryable: true);
            }

            $modelRow = $manager->reserveAvailableModel(
                preferredModelCode: $modelCode,
                estimatedTokens: $estimatedTokens,
                embedding: false,
                excludedIds: $triedIds,
                provider: 'gemini',
            );

            if (! $modelRow) {
                throw new AiProviderException(
                    "Gemini model {$modelCode} is unavailable or all of its keys are rate-limited.",
                    retryable: true,
                );
            }

            $triedIds[] = $modelRow->id;

            try {
                $response = Http::timeout((int) max(3, min($request->timeoutSeconds, $remaining)))
                    ->withHeaders(['x-goog-api-key' => $modelRow->apiKey->api_key])
                    ->post(
                        "https://generativelanguage.googleapis.com/v1beta/models/{$modelRow->model_code}:generateContent",
                        $payload
                    );

                if ($response->successful()) {
                    $json = $response->json();
                    $usedTokens = (int) data_get($json, 'usageMetadata.totalTokenCount', $estimatedTokens);
                    $manager->markUsed($modelRow, $usedTokens, $estimatedTokens);

                    return $this->parseResponse($json, $modelRow, $start);
                }

                $status = $response->status();
                $body = $response->body();

                if ($this->isInvalidApiKeyError($status, $body)) {
                    $modelRow->apiKey?->update(['is_active' => false, 'last_error' => mb_substr($body, 0, 2000)]);
                    $modelRow->update(['is_active' => false, 'last_error' => mb_substr($body, 0, 2000)]);

                    Log::warning('Gemini API key disabled because invalid', [
                        'key_id' => $modelRow->gemini_api_key_id,
                        'model' => $modelRow->model_code,
                    ]);

                    continue;
                }

                if ($status === 403 || $status === 404) {
                    $modelRow->update(['is_active' => false, 'last_error' => mb_substr($body, 0, 2000)]);

                    Log::warning('Gemini model disabled for this key', [
                        'key_id' => $modelRow->gemini_api_key_id,
                        'model' => $modelRow->model_code,
                        'status' => $status,
                    ]);

                    continue;
                }

                if ($status === 429 || $this->isQuotaError($body)) {
                    $rateLimit = app(GeminiRateLimitParser::class)->analyze($body, $response->header('Retry-After'));

                    $manager->markRateLimited(
                        model: $modelRow,
                        error: $body,
                        dailyLimit: $rateLimit['daily_limit'],
                        cooldownSeconds: $rateLimit['cooldown_seconds'],
                    );

                    continue;
                }

                if (in_array($status, [500, 502, 503, 504], true)) {
                    $manager->markError($modelRow, $body, 120);
                    $manager->refundReservation($modelRow);
                    $transientFailures++;

                    Log::warning('Gemini temporary server error, trying next key', [
                        'key_id' => $modelRow->gemini_api_key_id,
                        'model' => $modelRow->model_code,
                        'status' => $status,
                    ]);

                    if ($transientFailures <= $maxTransientFailovers) {
                        continue;
                    }

                    throw new AiProviderException('Gemini is temporarily unavailable.', retryable: true);
                }

                // Any other 4xx: an invalid request from our side. Another
                // credential/project would reproduce the same error, so we
                // do not fail over (DEC-10 rule 5).
                $manager->markError($modelRow, $body, 120);

                throw new AiProviderException(
                    "Gemini rejected the request (HTTP {$status}): ".mb_substr($body, 0, 500),
                    retryable: false,
                );
            } catch (ConnectionException $e) {
                $manager->markError($modelRow, $e->getMessage(), 120);
                $manager->refundReservation($modelRow);
                $transientFailures++;

                Log::error('Gemini request exception', [
                    'key_id' => $modelRow->gemini_api_key_id,
                    'model' => $modelRow->model_code,
                    'error' => $e->getMessage(),
                ]);

                // A timeout means the model itself is overloaded - another key
                // on the same model just waits out another full timeout.
                throw new AiProviderException(
                    'Gemini connection failed (model overloaded or unreachable).',
                    retryable: true,
                    previous: $e,
                );
            }
        }
    }

    private function estimateTokens(AiRequest $request): int
    {
        $chars = mb_strlen((string) $request->system);

        foreach ($request->contents as $turn) {
            foreach ($turn['parts'] ?? [] as $part) {
                if (($part['type'] ?? null) === 'text') {
                    $chars += mb_strlen((string) $part['text']);
                }
            }
        }

        return (int) ceil($chars / 4);
    }

    private function buildPayload(AiRequest $request): array
    {
        $payload = [
            'contents' => array_map(fn (array $turn) => $this->mapTurn($turn), $request->contents),
            'generationConfig' => [
                'temperature' => $request->temperature,
                'maxOutputTokens' => $request->maxOutputTokens,
            ],
        ];

        if ($request->thinkingLevel !== null) {
            $payload['generationConfig']['thinkingConfig'] = ['thinkingLevel' => $request->thinkingLevel];
        } elseif ($request->thinkingBudget !== null) {
            $payload['generationConfig']['thinkingConfig'] = ['thinkingBudget' => $request->thinkingBudget];
        }

        if ($request->responseSchema !== null) {
            $payload['generationConfig']['responseMimeType'] = 'application/json';
            $payload['generationConfig']['responseSchema'] = $request->responseSchema;
        }

        if ($request->system !== null && $request->system !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $request->system]]];
        }

        if ($request->tools !== []) {
            $payload['tools'] = [[
                'functionDeclarations' => array_map(static fn (array $tool) => array_filter([
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => $tool['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
                ], static fn ($v) => $v !== null), $request->tools),
            ]];

            $functionCallingConfig = ['mode' => strtoupper($request->toolMode)];

            if ($request->toolMode === 'any' && $request->allowedTools !== []) {
                $functionCallingConfig['allowedFunctionNames'] = $request->allowedTools;
            }

            $payload['toolConfig'] = ['functionCallingConfig' => $functionCallingConfig];
        }

        return $payload;
    }

    private function mapTurn(array $turn): array
    {
        return [
            // Gemini's contents API has only 'user' and 'model' roles; a
            // tool's reply is fed back as a 'user'-role functionResponse part.
            'role' => $turn['role'] === 'tool' ? 'user' : $turn['role'],
            'parts' => array_map(fn (array $part) => $this->mapPart($part), $turn['parts'] ?? []),
        ];
    }

    private function mapPart(array $part): array
    {
        return match ($part['type']) {
            'text' => ['text' => $part['text']],
            'inline_media' => array_filter([
                'inlineData' => ['mimeType' => $part['mime'], 'data' => $part['base64']],
                'mediaResolution' => isset($part['resolution']) ? ['level' => 'MEDIA_RESOLUTION_'.strtoupper($part['resolution'])] : null,
            ]),
            'tool_call' => array_filter([
                'functionCall' => array_filter([
                    'id' => $part['id'] ?? null,
                    'name' => $part['name'],
                    // Gemini's functionCall.args is a struct (JSON object), but PHP's []
                    // for "no arguments" encodes as a JSON array - reject it as invalid.
                    'args' => ($part['args'] ?? []) === [] ? new \stdClass() : $part['args'],
                ], static fn ($v) => $v !== null),
                'thoughtSignature' => $part['raw'] ?? null,
            ], static fn ($v) => $v !== null),
            'tool_result' => [
                'functionResponse' => array_filter([
                    'id' => $part['id'] ?? null,
                    'name' => $part['name'],
                    // Same struct-vs-list pitfall as functionCall.args above.
                    'response' => match (true) {
                        ($part['result'] ?? null) === [] => new \stdClass(),
                        is_array($part['result'] ?? null) => $part['result'],
                        default => ['result' => $part['result'] ?? null],
                    },
                ], static fn ($v) => $v !== null),
            ],
            default => throw new \InvalidArgumentException("Unknown AiRequest part type: {$part['type']}"),
        };
    }

    private function parseResponse(array $json, GeminiApiKeyModel $modelRow, float $start): AiResponse
    {
        $parts = data_get($json, 'candidates.0.content.parts', []);
        $finishReason = data_get($json, 'candidates.0.finishReason');

        $textParts = [];
        $toolCalls = [];
        $signatures = [];

        foreach ($parts as $index => $part) {
            if (isset($part['text'])) {
                $textParts[] = $part['text'];
            }

            if (isset($part['functionCall'])) {
                $id = $part['functionCall']['id'] ?? "call_{$index}";

                $toolCalls[] = [
                    'id' => $id,
                    'name' => $part['functionCall']['name'],
                    'args' => $part['functionCall']['args'] ?? [],
                ];

                if (isset($part['thoughtSignature'])) {
                    $signatures[$id] = $part['thoughtSignature'];
                }
            }
        }

        return new AiResponse(
            textParts: $textParts,
            toolCalls: $toolCalls,
            finishReason: $finishReason,
            usage: [
                'input_tokens' => (int) data_get($json, 'usageMetadata.promptTokenCount', 0),
                'output_tokens' => (int) data_get($json, 'usageMetadata.candidatesTokenCount', 0),
                'total_tokens' => (int) data_get($json, 'usageMetadata.totalTokenCount', 0),
                // Part of input_tokens served from Gemini's implicit cache.
                'cached_tokens' => (int) data_get($json, 'usageMetadata.cachedContentTokenCount', 0),
                'thoughts_tokens' => (int) data_get($json, 'usageMetadata.thoughtsTokenCount', 0),
            ],
            model: $modelRow->model_code,
            keyId: $modelRow->gemini_api_key_id,
            latencyMs: (int) round((microtime(true) - $start) * 1000),
            rawContinuation: $signatures !== [] ? ['tool_call_signatures' => $signatures] : [],
        );
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
