<?php

namespace App\Domain\Conversations;

use App\Models\GeminiApiKey;
use App\Models\MessageMedia;
use App\Services\GeminiKeyManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Owner 2026-10-04: the bot runs on GPT, and with the Gemini keys off every
 * voice note failed ("GPT can't hear"). Voice notes go to OpenAI's
 * transcription endpoint (config agent.voice_model, gpt-4o-mini-transcribe);
 * Gemini is only the fallback when no OpenAI key works.
 */
class OpenAiVoiceTranscriber implements VoiceTranscriber
{
    private const MAX_BYTES = 20 * 1024 * 1024;

    /** Steers spelling toward what our customers say - not instructions to follow. */
    private const PROMPT = 'عميل مصري بيكلم معرض موتوسيكلات على واتساب بالعامية: موتوسيكل، مكنة، قسط، تقسيط، مقدم، '
        .'كاش، بطاقة، مفردات مرتب، معاش، دليفري، أوبر، طلبات، دايو، هوجن، بوكسر، باجاج، كيواي، سكوتر.';

    public function __construct(private readonly GeminiVoiceTranscriber $fallback)
    {
    }

    public function transcribe(MessageMedia $media): TranscriptionResult
    {
        $disk = Storage::disk($media->disk);

        if (! $disk->exists($media->path) || $disk->size($media->path) > self::MAX_BYTES) {
            return TranscriptionResult::failed();
        }

        $key = $this->apiKey();

        if ($key === null) {
            return $this->fallback->transcribe($media);
        }

        $model = (string) (config('agent.voice_model') ?: 'gpt-4o-mini-transcribe');

        try {
            $response = Http::timeout(30)
                ->withToken($key->api_key)
                ->attach('file', $disk->get($media->path), $this->filename($media))
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => $model,
                    'language' => 'ar',
                    'prompt' => self::PROMPT,
                    'response_format' => 'json',
                ]);
        } catch (\Throwable $e) {
            Log::warning('OpenAI transcription failed', ['error' => $e->getMessage()]);

            return $this->fallback->transcribe($media);
        }

        if (! $response->successful()) {
            Log::warning('OpenAI transcription rejected', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);

            return $this->fallback->transcribe($media);
        }

        $this->recordUsage($key, $model, (array) $response->json('usage'));

        $text = trim((string) $response->json('text'));

        return TranscriptionResult::completed($text === '' ? null : $text);
    }

    private function apiKey(): ?GeminiApiKey
    {
        return GeminiApiKey::where('provider', 'openai')
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('cooldown_until')->orWhere('cooldown_until', '<=', now()))
            ->orderByDesc('is_paid')
            ->first();
    }

    private function filename(MessageMedia $media): string
    {
        $extension = match (true) {
            str_contains((string) $media->mime, 'ogg') => 'ogg',
            str_contains((string) $media->mime, 'mpeg'), str_contains((string) $media->mime, 'mp3') => 'mp3',
            str_contains((string) $media->mime, 'mp4'), str_contains((string) $media->mime, 'm4a'), str_contains((string) $media->mime, 'aac') => 'm4a',
            str_contains((string) $media->mime, 'wav') => 'wav',
            str_contains((string) $media->mime, 'webm') => 'webm',
            default => pathinfo((string) $media->path, PATHINFO_EXTENSION) ?: 'ogg',
        };

        return 'voice.'.$extension;
    }

    /** Same ledger as every other AI call, so the costs page shows voice too. */
    private function recordUsage(GeminiApiKey $key, string $model, array $usage): void
    {
        try {
            $input = (int) ($usage['input_tokens'] ?? 0);
            $output = (int) ($usage['output_tokens'] ?? 0);
            $price = \App\Models\AiModelPrice::where('model_code', $model)->first();

            \App\Models\AiUsageLog::create([
                'gemini_api_key_id' => $key->id,
                'model_code' => $model,
                'source' => GeminiKeyManager::purpose() ?? 'voice',
                'is_paid' => (bool) $key->is_paid,
                'input_tokens' => $input,
                'cached_tokens' => 0,
                'output_tokens' => $output,
                'thoughts_tokens' => 0,
                'cost_usd' => $price ? round(($input * $price->input_per_million + $output * $price->output_per_million) / 1_000_000, 6) : 0,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AI usage log failed', ['error' => $e->getMessage()]);
        }
    }
}
