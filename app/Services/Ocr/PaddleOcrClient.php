<?php

namespace App\Services\Ocr;

use Illuminate\Support\Facades\Http;

class PaddleOcrClient implements OcrProviderInterface
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

        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            return $this->failure('file_not_readable');
        }

        try {
            $request = Http::acceptJson()
                ->connectTimeout(max(1, (int) config('ocr.connect_timeout', 5)))
                ->timeout(max(10, (int) config('ocr.timeout', 90)));

            $token = (string) config('ocr.token', '');

            if ($token !== '') {
                $request = $request->withHeaders(['X-OCR-TOKEN' => $token]);
            }

            $response = $request
                ->attach(
                    'file',
                    $handle,
                    basename($absolutePath),
                    $mime !== '' ? ['Content-Type' => $mime] : []
                )
                ->post(config('ocr.url') . '/v1/ocr', [
                    'language' => 'ar',
                ]);

            if (! $response->successful()) {
                return $this->failure('ocr_service_error', $response->status());
            }

            $result = $response->json();

            if (! is_array($result) || ! ($result['ok'] ?? false)) {
                return $this->failure((string) ($result['error'] ?? 'invalid_ocr_response'));
            }

            $text = trim((string) ($result['text'] ?? ''));

            if ($text === '') {
                return $this->failure('ocr_no_text_detected');
            }

            return [
                'ok' => true,
                'text' => $text,
                'lines' => is_array($result['lines'] ?? null) ? $result['lines'] : [],
                'pages' => is_array($result['pages'] ?? null) ? $result['pages'] : [],
                'average_confidence' => isset($result['average_confidence'])
                    ? (float) $result['average_confidence']
                    : null,
                'document' => is_array($result['document'] ?? null) ? $result['document'] : [],
                'display_text' => trim((string) ($result['display_text'] ?? '')),
                'engine' => (string) ($result['engine'] ?? 'paddleocr'),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return $this->failure('ocr_service_unavailable');
        } finally {
            fclose($handle);
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
            'engine' => 'paddleocr',
            'error' => $error,
            'status' => $status,
        ];
    }
}

