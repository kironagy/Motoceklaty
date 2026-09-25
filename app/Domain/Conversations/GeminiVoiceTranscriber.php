<?php

namespace App\Domain\Conversations;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiProviderException;
use App\Agent\Providers\AiRequest;
use App\Models\MessageMedia;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GeminiVoiceTranscriber implements VoiceTranscriber
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    private const PROMPT = <<<'TXT'
        دي رسالة صوتية من عميل مصري بيكلم معرض موتوسيكلات.

        اكتب الكلام اللي فيها نصًا بالعامية المصرية زي ما اتقال
        بالظبط، من غير أي تلخيص أو تعليق أو ترجمة.

        لو مفيش كلام مفهوم في التسجيل (سكوت أو ضوضاء بس)، رد
        بكلمة واحدة: NO_SPEECH
        TXT;

    public function __construct(private readonly AiProvider $provider)
    {
    }

    public function transcribe(MessageMedia $media): TranscriptionResult
    {
        $disk = Storage::disk($media->disk);

        if (! $disk->exists($media->path) || $disk->size($media->path) > self::MAX_BYTES) {
            return TranscriptionResult::failed();
        }

        $bytes = $disk->get($media->path);

        if ($bytes === null || $bytes === '') {
            return TranscriptionResult::failed();
        }

        try {
            $response = $this->provider->chat(new AiRequest(
                system: self::PROMPT,
                contents: [
                    ['role' => 'user', 'parts' => [
                        ['type' => 'inline_media', 'mime' => $media->mime ?: 'audio/ogg', 'base64' => base64_encode($bytes)],
                    ]],
                ],
                toolMode: 'none',
                temperature: 0.1,
                maxOutputTokens: 500,
                thinkingBudget: 0,
                timeoutSeconds: 25,
            ));
        } catch (AiProviderException $e) {
            Log::warning('voice transcription provider failed', ['error' => $e->getMessage()]);

            return TranscriptionResult::failed();
        }

        $text = trim(implode('', $response->textParts));

        if ($text === '' || str_contains($text, 'NO_SPEECH')) {
            return TranscriptionResult::completed(null);
        }

        return TranscriptionResult::completed($text);
    }
}
