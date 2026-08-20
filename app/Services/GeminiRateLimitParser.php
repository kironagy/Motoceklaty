<?php

namespace App\Services;

class GeminiRateLimitParser
{
    public function analyze(string $body, ?string $retryAfterHeader = null): array
    {
        $payload = json_decode($body, true);
        $details = is_array($payload) ? data_get($payload, 'error.details', []) : [];

        $quotaParts = [];
        $retrySeconds = $this->parseRetryAfter($retryAfterHeader);

        if (is_array($details)) {
            foreach ($details as $detail) {
                if (! is_array($detail)) {
                    continue;
                }

                $type = mb_strtolower((string) ($detail['@type'] ?? ''));

                if (str_contains($type, 'retryinfo')) {
                    $retrySeconds = $this->parseDuration((string) ($detail['retryDelay'] ?? ''))
                        ?? $retrySeconds;
                }

                if (! str_contains($type, 'quotafailure')) {
                    continue;
                }

                foreach (($detail['violations'] ?? []) as $violation) {
                    if (! is_array($violation)) {
                        continue;
                    }

                    foreach (['quotaMetric', 'quotaId', 'subject', 'description'] as $key) {
                        if (! empty($violation[$key])) {
                            $quotaParts[] = (string) $violation[$key];
                        }
                    }
                }
            }
        }

        $quotaText = mb_strtolower(implode(' ', $quotaParts));
        $dailyLimit = $this->looksLikeDailyLimit($quotaText);

        if ($quotaText === '') {
            $dailyLimit = $this->looksLikeDailyLimit(mb_strtolower($body));
        }

        $defaultCooldown = (int) config('gemini.rate_limits.temporary_cooldown_seconds', 60);
        $maxCooldown = (int) config('gemini.rate_limits.max_cooldown_seconds', 3600);
        $cooldownSeconds = $retrySeconds ?? $defaultCooldown;
        $cooldownSeconds = max(1, min($cooldownSeconds, max(1, $maxCooldown)));

        return [
            'daily_limit' => $dailyLimit,
            'cooldown_seconds' => $cooldownSeconds,
            'quota' => $quotaText !== '' ? $quotaText : null,
        ];
    }

    private function looksLikeDailyLimit(string $text): bool
    {
        foreach (['perday', 'per day', 'requestsperday', 'tokensperday', 'rpd', 'tpd', 'daily'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function parseRetryAfter(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (is_numeric($value)) {
            return (int) ceil((float) $value);
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : max(1, $timestamp - time());
    }

    private function parseDuration(string $value): ?int
    {
        if (! preg_match('/^([0-9]+(?:\.[0-9]+)?)s$/', trim($value), $matches)) {
            return null;
        }

        return (int) ceil((float) $matches[1]);
    }
}
