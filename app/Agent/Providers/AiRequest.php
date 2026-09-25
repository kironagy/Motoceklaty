<?php

namespace App\Agent\Providers;

/**
 * Provider-neutral chat request.
 *
 * `contents` is a list of turns: ['role' => 'user'|'model'|'tool', 'parts' => [...]].
 * Each part is one of:
 *   ['type' => 'text', 'text' => string]
 *   ['type' => 'inline_media', 'mime' => string, 'base64' => string]
 *   ['type' => 'tool_call', 'id' => string, 'name' => string, 'args' => array, 'raw'?: array]
 *   ['type' => 'tool_result', 'id' => string, 'name' => string, 'result' => mixed]
 *
 * A tool_call part's optional `raw` key carries opaque provider continuation
 * data (e.g. Gemini's thoughtSignature) copied verbatim from a previous
 * AiResponse::$rawContinuation['tool_call_signatures'][$id] by whoever
 * assembles conversation history. Providers that don't need it ignore it.
 */
final class AiRequest
{
    public function __construct(
        public readonly ?string $system = null,
        public readonly array $contents = [],
        public readonly array $tools = [],
        public readonly string $toolMode = 'auto',
        public readonly array $allowedTools = [],
        public readonly float $temperature = 0.2,
        public readonly int $maxOutputTokens = 1024,
        public readonly ?int $thinkingBudget = null,
        public readonly int $timeoutSeconds = 30,
        public readonly ?array $responseSchema = null,
    ) {
    }
}
