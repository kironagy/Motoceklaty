<?php

namespace App\Filament\Concerns;

use App\Support\Access;
use Illuminate\Database\Eloquent\Model;

/**
 * A resource whose tab and actions follow the user's role (App\Support\Access).
 * Users without a role keep the resource's own rules (parent / overridden can* methods).
 */
trait RoleGatedResource
{
    /** null = roles don't apply to this user. */
    public static function roleAllows(string $ability): ?bool
    {
        return Access::check(static::class, $ability);
    }

    public static function canViewAny(): bool
    {
        return static::roleAllows('view') ?? parent::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::roleAllows('create') ?? parent::canCreate();
    }

    public static function canView(Model $record): bool
    {
        return static::roleAllows('show') ?? parent::canView($record);
    }

    public static function canEdit(Model $record): bool
    {
        return static::roleAllows('update') ?? parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::roleAllows('delete') ?? parent::canDelete($record);
    }

    public static function canDeleteAny(): bool
    {
        return static::roleAllows('delete') ?? parent::canDeleteAny();
    }
}
