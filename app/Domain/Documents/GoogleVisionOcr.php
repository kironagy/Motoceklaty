<?php

namespace App\Domain\Documents;

use Illuminate\Support\Facades\Http;

/**
 * DEC-22 (agreed 2026-09-23): Vision OCR only, for document text
 * extraction. No image bytes or extracted text are ever logged - only
 * request/response status is (Http::fake in tests asserts the same).
 */
class GoogleVisionOcr implements OcrProvider
{
    public function extractText(string $base64Image, string $mime): OcrResult
    {
        $apiKey = config('agent.ocr.google_vision_api_key');

        if (! $apiKey) {
            throw new OcrException('OCR_UNAVAILABLE: Google Vision API key is not configured.');
        }

        $response = Http::timeout((int) config('agent.ocr.timeout', 60))
            ->post("https://vision.googleapis.com/v1/images:annotate?key={$apiKey}", [
                'requests' => [[
                    'image' => ['content' => $base64Image],
                    'features' => [['type' => 'TEXT_DETECTION']],
                ]],
            ]);

        if (! $response->successful()) {
            throw new OcrException('OCR_UNAVAILABLE: Vision API request failed.');
        }

        $annotation = $response->json('responses.0.fullTextAnnotation.text');

        return new OcrResult($annotation ?? '');
    }
}
