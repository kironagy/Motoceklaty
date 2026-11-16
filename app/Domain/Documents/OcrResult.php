<?php

namespace App\Domain\Documents;

final class OcrResult
{
    public function __construct(
        public readonly string $text,
        /** Word boxes from Vision: [['text' => string, 'v' => [[x, y] x4]]], vertex 0 = the word's top-left as read. */
        public readonly array $words = [],
    ) {
    }

    public function toArray(): array
    {
        return ['text' => $this->text];
    }
}
