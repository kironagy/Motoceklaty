<?php

namespace App\Agent\Providers;

/**
 * Provider-neutral chat response.
 *
 * `toolCalls` items: ['id' => string, 'name' => string, 'args' => array].
 * `usage`: ['input_tokens' => int, 'output_tokens' => int, 'total_tokens' => int].
 * `rawContinuation` is opaque provider data the caller must round-trip; see
 * AiRequest's docblock for how Gemini's thoughtSignature is carried back.
 */
final class AiResponse
{
    public function __construct(
        public readonly array $textParts,
        public readonly array $toolCalls,
        public readonly ?string $finishReason,
        public readonly array $usage,
        public readonly string $model,
        public readonly ?int $keyId,
        public readonly int $latencyMs,
        public readonly array $rawContinuation = [],
    ) {
    }
}
