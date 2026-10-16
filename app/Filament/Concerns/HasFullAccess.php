<?php

namespace App\Filament\Concerns;

use App\Support\Access;

/**
 * A showroom / accounts / HR tab with the "صلاحية كاملة" column in the roles matrix.
 * fullAccess(): see everyone, approve, edit money, transfer. Otherwise the user works on his own records.
 */
trait HasFullAccess
{
    public static function hasFullAccessColumn(): bool
    {
        return true;
    }

    public static function fullAccess(): bool
    {
        return Access::full(static::class);
    }
}
