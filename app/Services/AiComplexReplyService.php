<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class AiComplexReplyService
{
    public function reply(string $message, array $conversationContext = []): array
    {
        /*
         * The caller (WhatsappIntentRouter::handleAiFallback) now passes the
         * classified intent through. Keep it in the context we hand to the
         * memory builder so its intent filter and scoring actually apply,
         * and fall back to the old constant when nothing was classified.
         */
        $intent = $conversationContext['intent'] ?? null;
        $intent = is_string($intent) && $intent !== '' ? $intent : 'fallback_complex';
        $conversationContext['intent'] = $intent;

        $memory = app(AiMemoryContextBuilder::class)->buildForMessage($message, $conversationContext);

        $prompt = app(AiPromptBuilder::class)->buildChatReplyPrompt(
            message: $message,
            memoryContext: $memory['context'] ?? '',
            intent: $intent,
            confidence: 'system',
            conversationContext: $conversationContext
        );

        $result = app(GeminiClient::class)->generateText(
            prompt: $prompt,
            preferredModelCode: config('gemini.models.reasoning'),
            options: [
                'timeout' => 20,
                /*
                 * Was temperature 0.2 / topP 0.4 / topK 5 - near-deterministic
                 * settings that made every reply read like the same canned
                 * sentence. Loosened so the wording varies while the facts
                 * still come from the memory context above.
                 */
                'temperature' => 0.6,
                'topP' => 0.9,
                /*
                 * The facts are already resolved and handed over in the
                 * memory context - this call only has to word them well, so
                 * thinking tokens would eat the reply budget for nothing.
                 */
                'thinkingBudget' => 0,
                // Was 260 - measured live truncating real replies (e.g. the
                // full branch list + map links) at exactly 597 chars every
                // time, mid-URL. 1024 gives real headroom for a legitimately
                // long structured answer while still bounding cost/rambling.
                'maxOutputTokens' => 1024,
            ]
        );

        if (! ($result['ok'] ?? false)) {
            Log::warning('AiComplexReplyService failed', [
                'message' => $message,
                'error' => $result['error'] ?? null,
            ]);

            return [
                'ok' => false,
                'reply' => null,
                'error' => $result['error'] ?? 'ai_failed',
                'intent' => $intent,
                'confidence' => 'system',
                'model' => $result['model'] ?? null,
                'key_id' => $result['key_id'] ?? null,
            ];
        }

        return [
            'ok' => true,
            'reply' => $this->cleanReply((string) ($result['reply'] ?? '')),
            'intent' => $intent,
            'confidence' => 'system',
            'model' => $result['model'] ?? null,
            'key_id' => $result['key_id'] ?? null,
        ];
    }

    private function cleanReply(string $reply): string
    {
        $reply = trim($reply);

        $reply = preg_replace('/^```[a-zA-Z]*\s*/u', '', $reply);
        $reply = preg_replace('/\s*```$/u', '', $reply);
        $reply = preg_replace("/\n{3,}/u", "\n\n", $reply);

        return trim($reply);
    }
}