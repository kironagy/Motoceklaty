<?php

namespace App\Agent\Providers;

use App\Services\GeminiKeyManager;
use Illuminate\Support\Facades\Log;

/**
 * Owner 2026-10-04: the bot may run on Gemini or GPT. The model name picks
 * the provider (gpt-* / o* = OpenAI, anything else = Gemini), so the primary
 * model and the fallbacks can mix both: gpt-5-nano first, Gemini behind it
 * when no GPT key is working.
 */
class RoutingAiProvider implements AiProvider
{
    /** Answers a voice note when every configured model is GPT. */
    private const AUDIO_MODEL = 'gemini-3.1-flash-lite';

    public function __construct(
        private readonly GeminiProvider $gemini,
        private readonly OpenAiProvider $openai,
    ) {
    }

    public static function providerFor(string $model): string
    {
        return preg_match('/^(gpt-|o\d)/i', $model) ? 'openai' : 'gemini';
    }

    public function chat(AiRequest $request): AiResponse
    {
        if ($request->purpose !== null && GeminiKeyManager::purpose() !== $request->purpose) {
            return GeminiKeyManager::for($request->purpose, fn () => $this->chat($request));
        }

        $modelCode = config('agent.model');

        if (! $modelCode) {
            throw new \RuntimeException('config("agent.model") is not set (AGENT_MODEL). See DEC-06.');
        }

        $fallbacks = array_values(array_filter(array_map('trim', explode(',', (string) config('agent.fallback_models')))));
        $models = array_values(array_unique(array_merge([$modelCode], $fallbacks)));

        // Documents have their own reader; the conversation models stay behind it.
        if ($request->purpose === 'document' && filled($documentModel = config('agent.document_model'))) {
            $models = array_values(array_unique(array_merge([trim((string) $documentModel)], $models)));
        }

        // GPT cannot hear a voice note
        if (! OpenAiProvider::accepts($request)) {
            $models = array_values(array_filter($models, fn ($m) => self::providerFor($m) === 'gemini')) ?: [self::AUDIO_MODEL];
        }

        // One budget for the whole call, across keys and models.
        // a document photo gets a longer budget: its reader and one fallback both get a real try
        $deadline = microtime(true) + (int) config('agent.provider_budget_seconds', 45) * ($request->purpose === 'document' ? 1.5 : 1);
        $firstGemini = null;

        foreach ($models as $index => $model) {
            // Real-customer run 2026-10-07: a clear tax card failed because the first model hung for the whole
            // 45s budget and the next one never got its turn. While another model is left, one that has not
            // answered in primary_timeout_seconds (documents: twice that - a photo takes longer) is given up on.
            $cap = (int) config('agent.primary_timeout_seconds', 15) * ($request->purpose === 'document' ? 2 : 1);
            $modelDeadline = $index < count($models) - 1 ? min($deadline, microtime(true) + $cap) : $deadline;

            try {
                if (self::providerFor($model) === 'openai') {
                    return $this->openai->chatWith($request, $model, $modelDeadline);
                }

                // Gemini's fallback thinking setting is for a Gemini model
                // behind another Gemini model, as before GPT existed.
                $firstGemini ??= $model;

                return $this->gemini->chatWith($request, $model, isFallback: $model !== $firstGemini, deadline: $modelDeadline);
            } catch (AiProviderException $e) {
                if (! $e->retryable || $index === count($models) - 1) {
                    throw $e;
                }

                Log::warning('AI model unavailable, using fallback model', ['from' => $model, 'error' => $e->getMessage()]);
            }
        }

        throw new AiProviderException('No AI model configured.', retryable: true);
    }
}
