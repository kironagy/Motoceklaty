<?php

namespace App\Agent\Context;

/** Same char/4 estimate used elsewhere (BusinessMemory::estimatedTokens(), GeminiClient) - no tokenizer dependency. */
class TokenEstimator
{
    public static function estimate(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }
}
