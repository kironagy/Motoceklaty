<?php

namespace App\Agent\Context;

use App\Models\AiCall;
use Illuminate\Support\Facades\Cache;

/**
 * Rebuild OBS-005: tokens from characters, at the ratio the provider really
 * billed. ai_calls keeps the characters sent next to the input tokens it
 * cost; the last 200 text-only calls give the chars-per-token of that
 * provider (Arabic is far below the old char/4). Until 20 such calls
 * exist, the configured default (4) stands.
 */
class TokenEstimator
{
    private const MIN_SAMPLES = 20;

    public static function estimate(string $text, ?string $provider = null): int
    {
        return (int) ceil(mb_strlen($text) / self::charsPerToken($provider));
    }

    public static function charsPerToken(?string $provider = null): float
    {
        $provider ??= \App\Agent\Providers\RoutingAiProvider::providerFor((string) config('agent.model', ''));

        return Cache::remember('agent.chars_per_token.'.$provider, 3600, function () use ($provider) {
            $default = (float) config('agent.tokens.default_chars_per_token', 4.0);

            try {
                $rows = AiCall::where('provider', $provider)->where('outcome', 'ok')
                    ->whereNotNull('prompt_chars')->where('input_tokens', '>', 0)
                    ->latest('id')->limit(200)->get(['prompt_chars', 'input_tokens']);
            } catch (\Throwable) {
                return $default;
            }

            if ($rows->count() < self::MIN_SAMPLES) {
                return $default;
            }

            return round($rows->sum('prompt_chars') / max(1, $rows->sum('input_tokens')), 3);
        });
    }
}
