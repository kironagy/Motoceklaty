<?php

namespace App\Agent\Tracing;

use App\Models\RequirementField;
use Illuminate\Support\Facades\Cache;

/**
 * Masks sensitive values before anything reaches a trace (plan principle
 * 13). Sensitive keys are `requirement_fields.is_sensitive` (T11) plus
 * config('agent.redaction.keys') for keys that aren't requirement fields
 * (e.g. `document_text`).
 */
class Redactor
{
    private const MASK = '[REDACTED]';

    private const CACHE_KEY = 'agent.redaction.sensitive_field_keys';

    public static function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $sensitiveKeys = array_merge(config('agent.redaction.keys', []), self::sensitiveFieldKeys());
            $out = [];

            foreach ($value as $key => $item) {
                $out[$key] = (is_string($key) && in_array($key, $sensitiveKeys, true))
                    ? self::MASK
                    : self::redact($item);
            }

            return $out;
        }

        if (is_string($value)) {
            // Egyptian national ID: 14 digits.
            return preg_replace('/\d{14}/', self::MASK, $value);
        }

        return $value;
    }

    /** @return string[] */
    private static function sensitiveFieldKeys(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => RequirementField::where('is_sensitive', true)->pluck('key')->all());
    }

    public static function invalidateSensitiveFieldKeysCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
