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
    public function __construct(
        private readonly GeminiProvider $gemini,
        private readonly OpenAiProvider $openai,
    ) {
    }

    public static function providerFor(string $model): string
    {
        if (str_starts_with($model, 'bormaa:')) {
            return 'bormaa';
        }

        return preg_match('/^(gpt-|o\d)/i', $model) ? 'openai' : 'gemini';
    }

    public function chat(AiRequest $request): AiResponse
    {
        if ($request->purpose !== null && GeminiKeyManager::purpose() !== $request->purpose) {
            return GeminiKeyManager::for($request->purpose, fn () => $this->chat($request));
        }

        $modelCode = $request->purpose === 'image' && filled(config('agent.image_model'))
            ? config('agent.image_model')
            : config('agent.model');
        $voiceTranscription = $request->purpose === 'voice' && $request->toolMode === 'none';
        if ($voiceTranscription && filled(config('agent.voice_model'))) $modelCode = config('agent.voice_model');

        if (! $modelCode) {
            throw new \RuntimeException('config("agent.model") is not set (AGENT_MODEL). See DEC-06.');
        }

        $fallbacks = array_values(array_filter(array_map('trim', explode(',', (string) config('agent.fallback_models')))));
        $models = array_values(array_unique(array_merge([$modelCode], $fallbacks)));

        // Server 2026-11-10: the dashboard named Bormaa models only; when the one Bormaa key died,
        // seven healthy Gemini keys sat idle and 125 customers got the outage line in a day. The
        // .env models stand behind whatever the dashboard picked, as the last line of defence.
        $models = array_values(array_unique(array_merge($models, self::envModels())));

        // Documents have their own reader; the conversation models stay behind it.
        if ($request->purpose === 'document' && filled($documentModel = config('agent.document_model'))) {
            $models = array_values(array_unique(array_merge([trim((string) $documentModel)], $models)));
        }

        // VoiceTranscriptionService transcribes voice with Gemini before the reply is built.
        // Never turn a failed transcription into a Gemini customer reply.
        if (! OpenAiProvider::accepts($request) && ! $voiceTranscription) {
            throw new AiProviderException('Audio must be transcribed before building a customer reply.', retryable: true);
        }
        if ($voiceTranscription) $models = array_values(array_filter($models, fn ($m) => self::providerFor($m) === 'gemini'));
        $models = self::allowedModels($request, $models);
        if ($models === []) throw new AiProviderException('No permitted model for this purpose.', retryable: false);

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
                $provider = self::providerFor($model);

                if (in_array($provider, ['openai', 'bormaa'], true)) {
                    return $this->openai->chatWith($request, $model, $modelDeadline, $provider);
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

    /**
     * Policy (2026-11-06): with Bormaa configured, Gemini only reads media and
     * Bormaa alone answers customers. Without any Bormaa model (local Gemini)
     * the configured models answer - the restriction used to leave no model
     * at all, so every customer reply failed with "No permitted model".
     */
    public static function allowedModels(AiRequest $request, array $models, ?bool $bormaaLive = null): array
    {
        $mediaReading = in_array($request->purpose, ['image','document'], true)
            || ($request->purpose === 'voice' && $request->toolMode === 'none');
        $bormaaConfigured = collect($models)->contains(fn ($model) => self::providerFor($model) === 'bormaa');

        if (! $bormaaConfigured) {
            return array_values($models);
        }

        // Bormaa with no usable key (disabled, revoked, parked) answers no one: its models are
        // dropped so the next provider gets the turn at once instead of the outage line.
        $bormaaLive ??= app(GeminiKeyManager::class)->hasLiveKey('bormaa');

        if (! $bormaaLive) {
            return array_values(array_filter($models, fn ($model) => self::providerFor($model) !== 'bormaa'));
        }

        if ($mediaReading) {
            return array_values($models);
        }

        return array_values(array_filter($models, fn ($model) => self::providerFor($model) === 'bormaa'));
    }

    /** The models from .env, ignoring the dashboard override. */
    private static function envModels(): array
    {
        $models = array_merge(
            [(string) \App\Domain\Settings\AgentSettings::envDefault('model')],
            explode(',', (string) \App\Domain\Settings\AgentSettings::envDefault('fallback_models')),
        );

        return array_values(array_filter(array_map('trim', $models)));
    }
}
