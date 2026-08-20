<?php

namespace App\Services\Ocr;

use Illuminate\Support\Facades\Http;

class GoogleVisionClient implements OcrProviderInterface
{
    public function recognize(string $absolutePath, ?string $mime = null): array
    {
        if (! config('ocr.enabled', true)) {
            return $this->failure('ocr_disabled');
        }

        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return $this->failure('file_not_found');
        }

        $maxBytes = max(1, (int) config('ocr.max_file_size_kb', 10240)) * 1024;
        $size = filesize($absolutePath);

        if ($size === false || $size > $maxBytes) {
            return $this->failure('file_too_large');
        }

        $mime = strtolower(trim((string) $mime));
        $allowedMimes = (array) config('ocr.allowed_mimes', []);

        if ($mime !== '' && ! in_array($mime, $allowedMimes, true)) {
            return $this->failure('unsupported_media_type');
        }

        $apiKey = (string) config('ocr.google_vision.api_key', '');

        if ($apiKey === '') {
            return $this->failure('google_vision_not_configured');
        }

        $content = @file_get_contents($absolutePath);

        if ($content === false) {
            return $this->failure('file_not_readable');
        }

        try {
            $response = Http::timeout(max(10, (int) config('ocr.google_vision.timeout', 60)))
                ->acceptJson()
                ->post('https://vision.googleapis.com/v1/images:annotate?key=' . $apiKey, [
                    'requests' => [
                        [
                            'image' => ['content' => base64_encode($content)],
                            'features' => [
                                ['type' => 'DOCUMENT_TEXT_DETECTION'],
                            ],
                            'imageContext' => [
                                'languageHints' => (array) config('ocr.google_vision.language_hints', ['ar']),
                            ],
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                return $this->failure('ocr_service_error', $response->status());
            }

            $result = $response->json('responses.0');

            if (! is_array($result)) {
                return $this->failure('invalid_ocr_response');
            }

            if (isset($result['error'])) {
                return $this->failure((string) ($result['error']['message'] ?? 'google_vision_error'));
            }

            $text = trim((string) ($result['fullTextAnnotation']['text'] ?? ''));

            if ($text === '') {
                return $this->failure('ocr_no_text_detected');
            }

            $lines = [];

            foreach (($result['textAnnotations'] ?? []) as $index => $annotation) {
                if ($index === 0) {
                    continue; // first entry is the full-text block, not a line
                }

                $lines[] = (string) ($annotation['description'] ?? '');
            }

            return [
                'ok' => true,
                'text' => $text,
                'lines' => $lines,
                'pages' => $result['fullTextAnnotation']['pages'] ?? [],
                'average_confidence' => null,
                'document' => $result['fullTextAnnotation'] ?? [],
                'display_text' => $text,
                'engine' => 'google_vision',
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return $this->failure('ocr_service_unavailable');
        }
    }

    private function failure(string $error, ?int $status = null): array
    {
        return [
            'ok' => false,
            'text' => '',
            'lines' => [],
            'pages' => [],
            'average_confidence' => null,
            'document' => [],
            'display_text' => '',
            'engine' => 'google_vision',
            'error' => $error,
            'status' => $status,
        ];
    }
}
