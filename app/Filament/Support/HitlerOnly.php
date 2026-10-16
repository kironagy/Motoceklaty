<?php

namespace App\Filament\Support;

/**
 * The AI controls and the website editor are for is_hitler staff only:
 * hidden from everyone else's menu, and a direct URL answers 403 - unless the user's role opens it.
 */
trait HitlerOnly
{
    public static function canAccess(): bool
    {
        return \App\Support\Access::check(static::class, 'view') ?? static::isHitler();
    }

    public static function isHitler(): bool
    {
        return (bool) auth()->user()?->is_hitler;
    }
}
