<?php

namespace App\Agent\Providers;

use App\Models\GeminiApiKeyModel;
use App\Services\GeminiKeyManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI (GPT) implementation, called by RoutingAiProvider for a gpt-* model.
 * Keys live in the same table as Gemini's (provider = openai), so they get
 * the same reservation, cooldown, paid/free pools and cost logging.
 *
 * Owner 2026-10-04: GPT keys are added from the dashboard next to Gemini's,
 * gpt-5-nano is the default model. Any request OpenAI rejects is retryable,
 * so the Gemini fallback answers the customer instead of the "busy" line.
 */
class OpenAiProvider
{
    private const URL = 'https://api.openai.com/v1/chat/completions';

    /** Media GPT reads; anything else (a voice note) goes to Gemini. */
    public static function accepts(AiRequest $request): bool
    {
        foreach ($request->contents as $turn) {
            foreach ($turn['parts'] ?? [] as $part) {
                if (($part['type'] ?? null) === 'inline_media'
                    && ! str_starts_with((string) $part['mime'], 'image/')
                    && $part['mime'] !== 'application/pdf') {
                    return false;
                }
            }
        }

        return true;
    }

    public function chatWith(AiRequest $request, string $modelCode, float $deadline = INF): AiResponse
    {
        $manager = app(GeminiKeyManager::class);
        $maxTransientFailovers = max(0, (int) config('gemini.rate_limits.max_transient_failovers', 2));
        $estimatedTokens = $this->estimateTokens($request);
        $payload = $this->buildPayload($request, $modelCode);
        $triedIds = [];
        $transientFailures = 0;
        $start = microtime(true);

        while (true) {
            $remaining = $deadline - microtime(true);

            if ($remaining < 3) {
                throw new AiProviderException('OpenAI call budget exhausted.', retryable: true);
            }

            $modelRow = $manager->reserveAvailableModel(
                preferredModelCode: $modelCode,
                estimatedTokens: $estimatedTokens,
                embedding: false,
                excludedIds: $triedIds,
                provider: 'openai',
            );

            if (! $modelRow) {
                throw new AiProviderException("OpenAI model {$modelCode} has no available key.", retryable: true);
            }

            $triedIds[] = $modelRow->id;

            try {
                $response = Http::timeout((int) max(3, min($request->timeoutSeconds, $remaining)))
                    ->withToken($modelRow->apiKey->api_key)
                    ->post(self::URL, $payload);
            } catch (ConnectionException $e) {
                $manager->markError($modelRow, $e->getMessage(), 30);
                $manager->refundReservation($modelRow);

                Log::error('OpenAI request exception', ['key_id' => $modelRow->gemini_api_key_id, 'model' => $modelCode, 'error' => $e->getMessage()]);

                throw new AiProviderException('OpenAI connection failed.', retryable: true, previous: $e);
            }

            if ($response->successful()) {
                $json = (array) $response->json();
                $usage = $this->usage($json);
                $manager->markUsed($modelRow, $usage['total_tokens'], $estimatedTokens);
                // recordUsage reads Gemini's usageMetadata shape
                $manager->recordUsage($modelRow, ['usageMetadata' => [
                    'promptTokenCount' => $usage['input_tokens'],
                    'cachedContentTokenCount' => $usage['cached_tokens'],
                    'candidatesTokenCount' => $usage['output_tokens'],
                    'thoughtsTokenCount' => $usage['thoughts_tokens'],
                ]], 'bot');

                return $this->parseResponse($json, $usage, $modelRow, $start);
            }

            $status = $response->status();
            $body = $response->body();
            $type = (string) data_get($response->json(), 'error.type');

            if ($status === 401) {
                $modelRow->apiKey?->update(['is_active' => false, 'last_error' => mb_substr($body, 0, 2000)]);
                Log::warning('OpenAI API key disabled because invalid', ['key_id' => $modelRow->gemini_api_key_id]);

                continue;
            }

            // No credit left on the account: park the key for a while instead
            // of hammering it on every message; a top-up works within minutes.
            if ($status === 429 && $type === 'insufficient_quota') {
                $manager->markRateLimited(model: $modelRow, error: $body, cooldownSeconds: 600);
                Log::warning('OpenAI key has no credit', ['key_id' => $modelRow->gemini_api_key_id]);

                continue;
            }

            if ($status === 429) {
                $manager->markRateLimited(model: $modelRow, error: $body, cooldownSeconds: max(5, (int) $response->header('Retry-After') ?: 10));
                $transientFailures++;

                if ($transientFailures <= $maxTransientFailovers) {
                    continue;
                }

                throw new AiProviderException('OpenAI is rate limited.', retryable: true);
            }

            if ($status === 403 || $status === 404) {
                // this key cannot use this model
                $modelRow->update(['is_active' => false, 'last_error' => mb_substr($body, 0, 2000)]);
                Log::warning('OpenAI model disabled for this key', ['key_id' => $modelRow->gemini_api_key_id, 'model' => $modelCode, 'status' => $status]);

                continue;
            }

            if ($status >= 500) {
                $manager->markError($modelRow, $body, 15);
                $manager->refundReservation($modelRow);
                $transientFailures++;

                if ($transientFailures <= $maxTransientFailovers) {
                    continue;
                }

                throw new AiProviderException('OpenAI is temporarily unavailable.', retryable: true);
            }

            // Another key would get the same 400, but Gemini may not.
            $manager->markError($modelRow, $body, 0);
            Log::error('OpenAI rejected the request', ['model' => $modelCode, 'status' => $status, 'body' => mb_substr($body, 0, 1000)]);

            throw new AiProviderException("OpenAI rejected the request (HTTP {$status}): ".mb_substr($body, 0, 500), retryable: true);
        }
    }

    private function buildPayload(AiRequest $request, string $modelCode): array
    {
        // Side calls (a thinkingBudget: work reading, address split) ran past
        // their timeouts at the bot's medium effort - the work checks were
        // skipped and addresses reached the dashboard unsplit. They read at
        // low. Documents have their own model and effort.
        $effort = $request->thinkingLevel
            ?: ($request->purpose === 'document' && filled(config('agent.document_reasoning_effort'))
                ? config('agent.document_reasoning_effort')
                : ($request->thinkingBudget !== null ? 'low' : (config('agent.openai_reasoning_effort') ?: 'minimal')));

        $payload = [
            'model' => $modelCode,
            'messages' => $this->messages($request),
            // GPT-5 accepts only its default temperature. Reasoning tokens
            // come out of max_completion_tokens, so they get room on top.
            'reasoning_effort' => $effort,
            'max_completion_tokens' => $request->maxOutputTokens + ($effort === 'minimal' ? 1024 : 4096),
        ];

        if ($request->responseSchema !== null) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'result', 'schema' => $this->schema($request->responseSchema), 'strict' => false],
            ];
        }

        if ($request->tools !== [] && $request->toolMode !== 'none') {
            $payload['tools'] = array_map(fn (array $tool) => ['type' => 'function', 'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'parameters' => $this->schema($tool['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()]),
            ]], $request->tools);

            $payload['tool_choice'] = match (true) {
                $request->toolMode !== 'any' => 'auto',
                $request->allowedTools === [] => 'required',
                count($request->allowedTools) === 1 => ['type' => 'function', 'function' => ['name' => $request->allowedTools[0]]],
                default => ['type' => 'allowed_tools', 'allowed_tools' => [
                    'mode' => 'required',
                    'tools' => array_map(fn ($name) => ['type' => 'function', 'function' => ['name' => $name]], $request->allowedTools),
                ]],
            };
        }

        return $payload;
    }

    /**
     * AiRequest turns to chat messages. History written by Gemini may carry
     * tool calls without ids or results without a call; OpenAI rejects both,
     * so ids are made up and pairs are completed here.
     */
    private function messages(AiRequest $request): array
    {
        $messages = [];

        if ($request->system !== null && $request->system !== '') {
            $messages[] = ['role' => 'system', 'content' => $request->system];
        }

        $pending = []; // call id => name, from the last assistant message

        foreach ($request->contents as $t => $turn) {
            $parts = $turn['parts'] ?? [];

            if ($turn['role'] === 'model') {
                $this->closePending($messages, $pending);
                $text = implode('', array_map(fn ($p) => $p['text'], array_filter($parts, fn ($p) => $p['type'] === 'text')));
                $calls = [];

                foreach ($parts as $i => $part) {
                    if ($part['type'] === 'tool_call') {
                        $id = (string) (($part['id'] ?? '') ?: "call_{$t}_{$i}");
                        $pending[$id] = $part['name'];
                        $calls[] = ['id' => $id, 'type' => 'function', 'function' => [
                            'name' => $part['name'],
                            'arguments' => json_encode(($part['args'] ?? []) ?: new \stdClass(), JSON_UNESCAPED_UNICODE),
                        ]];
                    }
                }

                $messages[] = $calls === []
                    ? ['role' => 'assistant', 'content' => $text]
                    : array_filter(['role' => 'assistant', 'content' => $text !== '' ? $text : null, 'tool_calls' => $calls], fn ($v) => $v !== null);

                continue;
            }

            $content = [];

            foreach ($parts as $part) {
                if ($part['type'] === 'tool_result') {
                    $id = $part['id'] ?? null;

                    if ($id === null || ! isset($pending[$id])) {
                        $id = array_search($part['name'], $pending, true);
                    }

                    $result = is_string($part['result'] ?? null) ? $part['result'] : json_encode($part['result'] ?? null, JSON_UNESCAPED_UNICODE);

                    if ($id !== false && $id !== null) {
                        unset($pending[$id]);
                        $messages[] = ['role' => 'tool', 'tool_call_id' => $id, 'content' => $result];
                    } else {
                        $content[] = ['type' => 'text', 'text' => "[{$part['name']} result] {$result}"];
                    }

                    continue;
                }

                $content[] = match ($part['type']) {
                    'text' => ['type' => 'text', 'text' => $part['text']],
                    'inline_media' => $part['mime'] === 'application/pdf'
                        ? ['type' => 'file', 'file' => ['filename' => 'document.pdf', 'file_data' => 'data:application/pdf;base64,'.$part['base64']]]
                        : ['type' => 'image_url', 'image_url' => ['url' => "data:{$part['mime']};base64,{$part['base64']}", 'detail' => $part['resolution'] ?? 'auto']],
                    // a tool call outside a model turn - keep it as text
                    default => ['type' => 'text', 'text' => json_encode($part, JSON_UNESCAPED_UNICODE)],
                };
            }

            if ($content !== []) {
                $this->closePending($messages, $pending);
                $messages[] = ['role' => 'user', 'content' => $content];
            }
        }

        $this->closePending($messages, $pending);

        return $messages;
    }

    /** Every tool call must be followed by its result. */
    private function closePending(array &$messages, array &$pending): void
    {
        foreach ($pending as $id => $name) {
            $messages[] = ['role' => 'tool', 'tool_call_id' => $id, 'content' => '{"status":"no result"}'];
        }

        $pending = [];
    }

    /** Gemini-style schema bits (upper-case types, nullable) to JSON Schema. */
    private function schema(mixed $schema): mixed
    {
        if (! is_array($schema)) {
            return $schema;
        }

        if (isset($schema['type']) && is_string($schema['type'])) {
            $schema['type'] = strtolower($schema['type']);

            if (! empty($schema['nullable'])) {
                $schema['type'] = [$schema['type'], 'null'];
            }
        }

        unset($schema['nullable']);

        foreach (['properties', 'items', 'anyOf'] as $key) {
            if (isset($schema[$key]) && is_array($schema[$key])) {
                $schema[$key] = $key === 'items' ? $this->schema($schema[$key]) : array_map(fn ($s) => $this->schema($s), $schema[$key]);
            }
        }

        if (($schema['type'] ?? null) === 'object' && ($schema['properties'] ?? []) === []) {
            $schema['properties'] = new \stdClass();
        }

        return $schema;
    }

    private function usage(array $json): array
    {
        $input = (int) data_get($json, 'usage.prompt_tokens', 0);
        $completion = (int) data_get($json, 'usage.completion_tokens', 0);
        $reasoning = (int) data_get($json, 'usage.completion_tokens_details.reasoning_tokens', 0);

        return [
            'input_tokens' => $input,
            // completion_tokens includes the reasoning; split them like Gemini
            'output_tokens' => max(0, $completion - $reasoning),
            'total_tokens' => (int) data_get($json, 'usage.total_tokens', $input + $completion),
            'cached_tokens' => (int) data_get($json, 'usage.prompt_tokens_details.cached_tokens', 0),
            'thoughts_tokens' => $reasoning,
        ];
    }

    private function parseResponse(array $json, array $usage, GeminiApiKeyModel $modelRow, float $start): AiResponse
    {
        $message = (array) data_get($json, 'choices.0.message', []);
        $toolCalls = [];

        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            $args = json_decode((string) data_get($call, 'function.arguments', '{}'), true);
            $toolCalls[] = ['id' => (string) $call['id'], 'name' => (string) data_get($call, 'function.name'), 'args' => is_array($args) ? $args : []];
        }

        $content = $message['content'] ?? null;

        return new AiResponse(
            textParts: is_string($content) && $content !== '' ? [$content] : [],
            toolCalls: $toolCalls,
            // the names the rest of the code knows from Gemini
            finishReason: match (data_get($json, 'choices.0.finish_reason')) {
                'length' => 'MAX_TOKENS',
                'content_filter' => 'SAFETY',
                null => null,
                default => 'STOP',
            },
            usage: $usage,
            model: $modelRow->model_code,
            keyId: $modelRow->gemini_api_key_id,
            latencyMs: (int) round((microtime(true) - $start) * 1000),
        );
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
}
