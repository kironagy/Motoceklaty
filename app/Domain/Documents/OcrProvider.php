<?php

namespace App\Domain\Documents;

interface OcrProvider
{
    /**
     * @throws OcrException
     */
    public function extractText(string $base64Image, string $mime): OcrResult;
}
