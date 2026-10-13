<?php

namespace App\Filament\Support;

/**
 * The AI controls and the website editor are for is_hitler staff only:
 * hidden from everyone else's menu, and a direct URL answers 403.
 */
trait HitlerOnly
{
    public static function canAccess(): bool
    {
        return static::isHitler();
    }

    public static function isHitler(): bool
    {
        return (bool) auth()->user()?->is_hitler;
    }
}
