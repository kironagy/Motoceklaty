<?php

namespace App\Support;

use App\Models\Staff;

/**
 * Who may do what in the showroom / finance screens (merged from env.motocyklaty.store).
 *
 * The finance role is its own field (staff.finance_role) and is not the bot-dashboard admin flags:
 * env "super_admin" sees every showroom and the money, "admin" only his showroom, "employee" only
 * his own requests. Staff without a finance role (bot-only accounts) see none of these screens.
 * The owner (is_hitler) counts as finance super admin.
 */
class FinanceAccess
{
    public const ROLES = [
        'employee' => 'موظف',
        'admin' => 'أدمن',
        'super_admin' => 'سوبر أدمن',
    ];

    public static function user(?Staff $staff = null): ?Staff
    {
        if ($staff) {
            return $staff;
        }

        $user = auth('filament')->user();

        return $user instanceof Staff ? $user : null;
    }

    public static function role(?Staff $staff = null): ?string
    {
        $staff = static::user($staff);

        if (! $staff) {
            return null;
        }

        if ((bool) ($staff->is_hitler ?? false)) {
            return 'super_admin';
        }

        return array_key_exists((string) $staff->finance_role, self::ROLES) ? $staff->finance_role : null;
    }

    public static function hasAccess(?Staff $staff = null): bool
    {
        return static::role($staff) !== null;
    }

    public static function isSuper(?Staff $staff = null): bool
    {
        return static::role($staff) === 'super_admin';
    }

    /** Showroom admin (not super): sees his showroom only and can't edit money on letters. */
    public static function isAdmin(?Staff $staff = null): bool
    {
        return static::role($staff) === 'admin';
    }

    public static function canManage(?Staff $staff = null): bool
    {
        return in_array(static::role($staff), ['admin', 'super_admin'], true);
    }

    public static function isShowroomEmployee(?Staff $staff = null): bool
    {
        return (bool) static::user($staff)?->is_showroom_employee;
    }
}
