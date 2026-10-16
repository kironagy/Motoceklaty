<?php

namespace App\Filament\Concerns;

use App\Support\Access;

/** A page that opens only for roles that include it; users without a role keep the page's own rule. */
trait RoleGatedPage
{
    public static function canAccess(): bool
    {
        return Access::check(static::class, 'view') ?? parent::canAccess();
    }
}
