<?php

namespace App\Domain\Documents;

final class OcrResult
{
    public function __construct(
        public readonly string $text,
    ) {
    }

    public function toArray(): array
    {
        return ['text' => $this->text];
    }
}
