<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * تفريغ الرسائل الصوتية لنص.
 *
 * كان البوت بيرد على أي فويس بـ"مقدرش أسمع الرسائل الصوتية، اكتبلي" -
 * وبعد تلات فويسات بيحوّل لموظف. وده أسوأ حاجة ممكنة لشريحة عملائنا
 * الأساسية: مندوبين التوصيل بيبعتوا فويس وهما على الموتوسيكل، والكتابة
 * أصعب عليهم من الكلام. يعني كنا بنرمي أكتر عميل مؤهل عندنا على موظف
 * بشري من غير سبب تقني حقيقي - Gemini بيفرّغ الصوت عادي.
 *
 * الملف بيتبعت للموديل كـ inlineData زي الصور بالظبط. لو التفريغ فشل
 * بنرجع null والراوتر بيرجع للسلوك القديم (نطلب منه يكتب) - فأسوأ حالة
 * هي اللي كانت شغالة قبل كده.
 */
class VoiceTranscriptionService
{
    /** أطول فويس هنحاول نفرّغه. أطول من كده غالبًا مش سؤال، ده حكاية. */
    private const MAX_BYTES = 8 * 1024 * 1024;

    /**
     * @param  array<int,array<string,mixed>>  $mediaItems
     * @return string|null نص الكلام، أو null لو مقدرناش
     */
    public function transcribe(array $mediaItems): ?string
    {
        $item = collect($mediaItems)->first(
            fn ($i) => is_array($i) && trim((string) ($i['path'] ?? '')) !== ''
        );

        if (! $item) {
            return null;
        }

        $disk = Storage::disk('public');
        $path = trim((string) $item['path']);

        if (! $disk->exists($path)) {
            return null;
        }

        $absolute = $disk->path($path);

        if (filesize($absolute) > self::MAX_BYTES) {
            return null;
        }

        $bytes = @file_get_contents($absolute);

        if ($bytes === false || $bytes === '') {
            return null;
        }

        $mime = strtolower(trim((string) ($item['mime'] ?? 'audio/ogg')));

        try {
            $result = app(GeminiClient::class)->generateText(
                prompt: <<<'TXT'
                دي رسالة صوتية من عميل مصري بيكلم معرض موتوسيكلات.

                اكتب الكلام اللي فيها نصًا بالعامية المصرية زي ما اتقال
                بالظبط، من غير أي تلخيص أو تعليق أو ترجمة.

                لو مفيش كلام مفهوم في التسجيل (سكوت أو ضوضاء بس)، رد
                بكلمة واحدة: NO_SPEECH
                TXT,
                preferredModelCode: config('gemini.models.fast'),
                options: [
                    'image_base64' => base64_encode($bytes),
                    'image_mime' => $mime ?: 'audio/ogg',
                    'timeout' => 25,
                    'temperature' => 0.1,
                    'thinkingBudget' => 0,
                    'maxOutputTokens' => 500,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('voice transcription failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! ($result['ok'] ?? false)) {
            return null;
        }

        $text = trim((string) ($result['reply'] ?? ''));

        if ($text === '' || str_contains($text, 'NO_SPEECH')) {
            return null;
        }

        Log::info('voice_transcribed', ['chars' => mb_strlen($text)]);

        return $text;
    }
}
