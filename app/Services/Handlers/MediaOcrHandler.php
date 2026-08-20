<?php

namespace App\Services\Handlers;

use App\Models\WhatsappConversation;
use App\Services\Ocr\OcrProviderInterface;
use Illuminate\Support\Facades\Storage;

class MediaOcrHandler
{
    public function handle(
        WhatsappConversation $conversation,
        array $mediaItems,
        string $message = ''
    ): array {
        $results = [];

        foreach ($mediaItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $path = trim((string) ($item['path'] ?? ''));
            $mime = strtolower(trim((string) ($item['mime'] ?? '')));

            if ($path === '' || ! $this->isOcrSupported($mime)) {
                $results[] = $this->mediaResult($item, [
                    'ok' => false,
                    'error' => 'unsupported_media_type',
                ]);

                continue;
            }

            $disk = Storage::disk('public');

            if (! $disk->exists($path)) {
                $results[] = $this->mediaResult($item, [
                    'ok' => false,
                    'error' => 'file_not_found',
                ]);

                continue;
            }

            $ocr = app(OcrProviderInterface::class)->recognize($disk->path($path), $mime);
            $results[] = $this->mediaResult($item, $ocr);
        }

        $successful = collect($results)->where('ok', true)->values();
        $status = $successful->isNotEmpty()
            ? ($successful->count() === count($results) ? 'completed' : 'partial')
            : 'failed';

        $this->saveOcrResults($conversation, $results, $status);

        if ($successful->isNotEmpty()) {
            return $this->reply(
                $conversation,
                'تمام يا فندم، استلمت المستندات وقريت البيانات المكتوبة فيها. هراجع المطلوب وأقولك لو فيه حاجة ناقصة.',
                $status,
                false
            );
        }

        return $this->reply(
            $conversation,
            'تمام يا فندم، استلمت الملفات. هتتحول للمراجعة عشان نتأكد من البيانات ونقولك لو فيه حاجة ناقصة.',
            $status,
            true
        );
    }

    private function isOcrSupported(string $mime): bool
    {
        return in_array($mime, (array) config('ocr.allowed_mimes', []), true);
    }

    private function mediaResult(array $item, array $ocr): array
    {
        return [
            'path' => $item['path'] ?? null,
            'filename' => $item['filename'] ?? null,
            'mime' => $item['mime'] ?? null,
            'type' => $item['type'] ?? null,
            'ok' => (bool) ($ocr['ok'] ?? false),
            'text' => trim((string) ($ocr['text'] ?? '')),
            'lines' => is_array($ocr['lines'] ?? null) ? $ocr['lines'] : [],
            'pages' => is_array($ocr['pages'] ?? null) ? $ocr['pages'] : [],
            'average_confidence' => $ocr['average_confidence'] ?? null,
            'document' => is_array($ocr['document'] ?? null) ? $ocr['document'] : [],
            'display_text' => trim((string) ($ocr['display_text'] ?? '')),
            'engine' => $ocr['engine'] ?? 'paddleocr',
            'error' => $ocr['error'] ?? null,
        ];
    }

    private function saveOcrResults(
        WhatsappConversation $conversation,
        array $results,
        string $status
    ): void {
        $resultPaths = collect($results)
            ->pluck('path')
            ->filter()
            ->values();

        $message = $conversation->messages()
            ->where('direction', 'incoming')
            ->latest('id')
            ->take(20)
            ->get()
            ->first(function ($message) use ($resultPaths) {
                $savedPaths = collect(data_get($message->payload, 'saved_media_items', []))
                    ->pluck('path')
                    ->filter();

                return $resultPaths->intersect($savedPaths)->isNotEmpty();
            });

        if (! $message) {
            return;
        }

        $payload = $message->payload ?? [];

        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?: [];
        }

        if (! is_array($payload)) {
            $payload = [];
        }

        $payload['ocr_status'] = $status;
        $payload['ocr_results'] = $results;
        $payload['ocr_processed_at'] = now()->toIso8601String();

        $message->forceFill(['payload' => $payload])->save();
    }

    private function reply(
        WhatsappConversation $conversation,
        string $reply,
        string $status,
        bool $requiresHumanReview
    ): array {
        $conversation->messages()->create([
            'direction' => 'outgoing',
            'message' => $reply,
            'payload' => [
                'source' => 'paddle_ocr_handler',
                'ocr_status' => $status,
                'requires_human_review' => $requiresHumanReview,
            ],
        ]);

        return [
            'handled' => true,
            'type' => 'text',
            'reply' => $reply,
            'image' => null,
            'images' => [],
            'image_items' => [],
            'image_groups' => [],
            'intent' => 'document_ocr',
            'source' => 'paddle_ocr_handler',
        ];
    }
}

