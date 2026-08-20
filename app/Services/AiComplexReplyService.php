<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class AiComplexReplyService
{
    public function reply(string $message, array $conversationContext = []): array
    {
        $memory = app(AiMemoryContextBuilder::class)->buildForMessage($message, $conversationContext);

        $prompt = app(AiPromptBuilder::class)->buildChatReplyPrompt(
            message: $message,
            memoryContext: $memory['context'] ?? '',
            intent: 'fallback_complex',
            confidence: 'system',
            conversationContext: $conversationContext
        );

        $result = app(GeminiClient::class)->generateText(
            prompt: $prompt,
            preferredModelCode: 'gemini-3.1-flash-lite',
            options: [
                'timeout' => 20,
                'temperature' => 0.2,
                'topP' => 0.4,
                'topK' => 5,
                'maxOutputTokens' => 260,
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
                'intent' => 'fallback_complex',
                'confidence' => 'system',
            ];
        }

        return [
            'ok' => true,
            'reply' => $this->cleanReply((string) ($result['reply'] ?? '')),
            'intent' => 'fallback_complex',
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