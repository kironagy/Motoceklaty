<?php

namespace App\Agent\Tracing;

use App\Agent\Providers\AiRequest;
use App\Models\AiCall;
use App\Services\GeminiKeyManager;
use Illuminate\Support\Facades\Log;

/**
 * Rebuild OBS-001/002/003: every model HTTP attempt becomes an ai_calls row
 * tied to the turn that caused it, so a turn's real cost (main loop plus
 * understanding, reviewer, work reading, documents...) is one query.
 *
 * The turn is a scope: the turn processor opens it, the runner names the
 * trace, and every provider attempt inside it - however deep (a tool calling
 * the work classifier) - is counted against that turn.
 */
class AiCalls
{
    /** @var list<array{turn_id: ?int, conversation_id: ?int, trace_id: ?int, runner: ?string, calls: int, ok: array<string, int>}> */
    private static array $scopes = [];

    public static function within(array $scope, callable $callback): mixed
    {
        self::$scopes[] = [
            'turn_id' => $scope['turn_id'] ?? null,
            'conversation_id' => $scope['conversation_id'] ?? null,
            'trace_id' => $scope['trace_id'] ?? null,
            'runner' => $scope['runner'] ?? null,
            'calls' => 0,
            'ok' => [],
        ];

        try {
            return $callback();
        } finally {
            array_pop(self::$scopes);
        }
    }

    public static function setTrace(int $traceId, ?string $runner = null): void
    {
        if (self::$scopes !== []) {
            $last = array_key_last(self::$scopes);
            self::$scopes[$last]['trace_id'] = $traceId;
            self::$scopes[$last]['runner'] = $runner ?? self::$scopes[$last]['runner'];
        }
    }

    /** Successful + failed attempts made inside the current turn so far (ARCH-004 budget). */
    public static function countInTurn(): int
    {
        return self::$scopes === [] ? 0 : self::$scopes[array_key_last(self::$scopes)]['calls'];
    }

    /** Answered model calls in the current turn, by label - failed key attempts are not calls (ARCH-004). */
    public static function okCallsInTurn(?string $exceptLabel = null): int
    {
        if (self::$scopes === []) {
            return 0;
        }

        $ok = self::$scopes[array_key_last(self::$scopes)]['ok'];
        unset($ok[$exceptLabel ?? '']);

        return array_sum($ok);
    }

    public static function okCallsOfLabel(string $label): int
    {
        return self::$scopes === [] ? 0 : (int) (self::$scopes[array_key_last(self::$scopes)]['ok'][$label] ?? 0);
    }

    /**
     * OBS-002: every call the turn made, side calls included, for the trace.
     *
     * @return array{total: int, failed: int, by_label: array<string, int>, input_tokens: int, output_tokens: int, reasoning_tokens: int}
     */
    public static function summaryForTrace(int $traceId): array
    {
        $rows = AiCall::where('trace_id', $traceId)->get(['label', 'outcome', 'input_tokens', 'output_tokens', 'reasoning_tokens']);

        return [
            'total' => $rows->count(),
            'failed' => $rows->where('outcome', '!=', 'ok')->count(),
            'by_label' => $rows->countBy('label')->sortKeys()->all(),
            'input_tokens' => (int) $rows->sum('input_tokens'),
            'output_tokens' => (int) $rows->sum('output_tokens'),
            'reasoning_tokens' => (int) $rows->sum('reasoning_tokens'),
        ];
    }

    /**
     * @param  array{input_tokens?: int, cached_tokens?: int, output_tokens?: int, thoughts_tokens?: int}  $usage
     */
    public static function record(AiRequest $request, string $provider, string $model, int $attempt, array $usage, int $latencyMs, string $outcome, mixed $response = null): void
    {
        $scope = self::$scopes === [] ? null : self::$scopes[array_key_last(self::$scopes)];

        $label = mb_substr($request->label ?? GeminiKeyManager::purpose() ?? 'other', 0, 30);

        if ($scope !== null) {
            $last = array_key_last(self::$scopes);
            self::$scopes[$last]['calls']++;

            if ($outcome === 'ok') {
                self::$scopes[$last]['ok'][$label] = (self::$scopes[$last]['ok'][$label] ?? 0) + 1;
            }
        }

        try {
            AiCall::create([
                'trace_id' => $scope['trace_id'] ?? null,
                'turn_id' => $scope['turn_id'] ?? null,
                'conversation_id' => $scope['conversation_id'] ?? null,
                'runner' => $scope['runner'] ?? null,
                'label' => $label,
                'purpose' => GeminiKeyManager::purpose(),
                'provider' => $provider,
                'model' => mb_substr($model, 0, 80),
                'attempt' => min(255, max(1, $attempt)),
                'prompt_chars' => self::promptChars($request),
                'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
                'cached_tokens' => (int) ($usage['cached_tokens'] ?? 0),
                'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
                'reasoning_tokens' => (int) ($usage['thoughts_tokens'] ?? 0),
                'latency_ms' => max(0, $latencyMs),
                'outcome' => mb_substr($outcome, 0, 30),
                'capture' => self::capture($request, $response),
            ]);
        } catch (\Throwable $e) {
            Log::warning('ai_calls row failed', ['error' => $e->getMessage()]);
        }
    }

    /** Text characters of the request; null when it carries media (images cost tokens no character count explains). */
    public static function promptChars(AiRequest $request): ?int
    {
        $chars = mb_strlen((string) $request->system);

        foreach ($request->contents as $turn) {
            foreach ($turn['parts'] ?? [] as $part) {
                if (($part['type'] ?? null) === 'inline_media') {
                    return null;
                }

                $chars += match ($part['type'] ?? null) {
                    'text' => mb_strlen((string) ($part['text'] ?? '')),
                    'tool_call' => mb_strlen((string) json_encode($part['args'] ?? [], JSON_UNESCAPED_UNICODE)),
                    'tool_result' => mb_strlen((string) json_encode($part['result'] ?? null, JSON_UNESCAPED_UNICODE)),
                    default => 0,
                };
            }
        }

        return $chars + ($request->tools === [] ? 0 : mb_strlen((string) json_encode($request->tools, JSON_UNESCAPED_UNICODE)));
    }

    /**
     * OBS-003: the prompt and the answer, only when switched on, sampled,
     * with phones and national IDs masked. Off by default.
     */
    private static function capture(AiRequest $request, mixed $response): ?array
    {
        if (! config('agent.observability.capture.enabled')) {
            return null;
        }

        $rate = (float) config('agent.observability.capture.sample_rate', 1.0);

        if ($rate < 1.0 && mt_rand() / mt_getrandmax() > $rate) {
            return null;
        }

        $contents = array_map(fn (array $turn) => [
            'role' => $turn['role'] ?? null,
            'parts' => array_map(fn ($part) => ($part['type'] ?? null) === 'inline_media'
                ? ['type' => 'inline_media', 'mime' => $part['mime'] ?? null]
                : $part, $turn['parts'] ?? []),
        ], $request->contents);

        return self::mask([
            'system' => $request->system,
            'contents' => $contents,
            'response' => $response,
        ]);
    }

    /** Egyptian mobiles (01x + 8 digits) and 14-digit national IDs, in Arabic or Latin digits. */
    public static function mask(mixed $value): mixed
    {
        if (is_array($value)) {
            return Redactor::redact(array_map(fn ($item) => self::mask($item), $value));
        }

        if (! is_string($value)) {
            return $value;
        }

        $latin = strtr($value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $masked = preg_replace(['/(?<!\d)\d{14}(?!\d)/', '/(?<!\d)(?:\+?20|0)1[0125]\d{8}(?!\d)/'], ['[NATIONAL_ID]', '[PHONE]'], $latin);

        return $masked === $latin ? $value : $masked;
    }
}
