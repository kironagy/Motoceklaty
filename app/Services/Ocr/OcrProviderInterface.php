<?php

namespace App\Services\Ocr;

interface OcrProviderInterface
{
    /**
     * Recognize text in a document/image. Return shape matches
     * PaddleOcrClient::recognize() exactly, so any provider is a drop-in
     * replacement for MediaOcrHandler with no caller-side changes:
     *
     * [
     *   'ok' => bool,
     *   'text' => string,
     *   'lines' => array,
     *   'pages' => array,
     *   'average_confidence' => float|null,
     *   'document' => array,
     *   'display_text' => string,
     *   'engine' => string,
     *   'error' => string|null,
     * ]
     */
    public function recognize(string $absolutePath, ?string $mime = null): array;
}
