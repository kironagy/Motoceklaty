<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Carbon;

/**
 * Stores and reads the attribute as an explicit UTC instant instead of
 * relying on PHP's ambient default timezone, which Carbon::setTestNow()
 * silently overrides for parsing whenever it's given an explicit offset
 * (e.g. '... UTC') that differs from config('app.timezone').
 */
class UtcDateTimeCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::createFromFormat('Y-m-d H:i:s', $value, 'UTC');
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        return $value === null ? null : Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
    }
}
