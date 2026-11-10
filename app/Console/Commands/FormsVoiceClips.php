<?php

namespace App\Console\Commands;

use App\Models\GeminiApiKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Records the form's voice guidance (resources/forms/voice-eg.json) with Gemini TTS into
 * public/audio/form-eg-v1. Only clips without a file are recorded unless --force names them,
 * so the voices already in use do not change.
 */
class FormsVoiceClips extends Command
{
    protected $signature = 'forms:voice-clips {--force=* : clip ids to record again} {--model= : TTS model (default: the one in voice-eg.json)} {--dry-run : list what would be recorded}';

    protected $description = 'Record missing form voice clips with Gemini TTS';

    public function handle(): int
    {
        $config = json_decode(file_get_contents(resource_path('forms/voice-eg.json')), true, 512, JSON_THROW_ON_ERROR);
        $dir = public_path('audio/form-eg-v1');
        $force = (array) $this->option('force');
        $todo = array_filter($config['clips'], fn ($text, $id) => in_array($id, $force, true) || ! is_file("{$dir}/{$id}.wav"), ARRAY_FILTER_USE_BOTH);

        if ($todo === []) {
            $this->info('Every clip is recorded.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line(implode(PHP_EOL, array_keys($todo)));

            return self::SUCCESS;
        }

        $key = config('services.gemini_tts.api_key') ?: GeminiApiKey::where('is_active', true)->where('provider', 'gemini')->orderByDesc('is_paid')->value('api_key');

        if (! $key) {
            $this->error('No Gemini key: set GEMINI_TTS_API_KEY or add an active Gemini key.');

            return self::FAILURE;
        }

        $model = $this->option('model') ?: $config['model'];
        $failed = 0;

        foreach ($todo as $id => $text) {
            $response = Http::timeout(90)->withHeaders(['x-goog-api-key' => $key])
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                    'contents' => [['role' => 'user', 'parts' => [['text' => $config['instructions']."\n\n".$text]]]],
                    'generationConfig' => [
                        'responseModalities' => ['AUDIO'],
                        'speechConfig' => ['voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $config['voice']]]],
                    ],
                ]);

            $part = $response->json('candidates.0.content.parts.0.inlineData');

            if (! $response->ok() || blank($part['data'] ?? null)) {
                $this->error("{$id}: ".$response->status().' '.mb_substr($response->body(), 0, 300));
                $failed++;

                continue;
            }

            $audio = base64_decode($part['data']);

            // older TTS models answer with headerless 16-bit PCM ("audio/L16;codec=pcm;rate=24000")
            if (! str_starts_with($audio, 'RIFF')) {
                $rate = preg_match('/rate=(\d+)/', (string) ($part['mimeType'] ?? ''), $m) ? (int) $m[1] : 24000;
                $audio = $this->wav($audio, $rate);
            }

            file_put_contents("{$dir}/{$id}.wav", $audio);
            $this->info("{$id}: recorded");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** Mono 16-bit PCM wrapped in a WAV header. */
    private function wav(string $pcm, int $rate): string
    {
        return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
    }
}
