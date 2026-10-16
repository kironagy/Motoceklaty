<?php

namespace App\Support;

use App\Models\Staff;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * Dashboard roles. Every resource and page of the admin panel is a tab; a role (or a staff member's
 * custom permissions) lists, per tab, which of view / create / show / update / delete are open.
 *
 * check() answers null when roles don't apply to the user - the owner (is_hitler) and staff with
 * neither a role nor custom permissions - and the tab then falls back to its own built-in rules.
 */
class Access
{
    public const ABILITIES = [
        'view' => 'يشوف التاب',
        'create' => 'إضافة',
        'show' => 'عرض',
        'update' => 'تعديل',
        'delete' => 'حذف',
    ];

    public const PAGE_ABILITIES = ['view' => 'يشوف الصفحة'];

    public static function user(): ?Staff
    {
        $user = auth('filament')->user();

        return $user instanceof Staff ? $user : null;
    }

    /** The permissions that govern this user, or null when roles don't apply to him. */
    public static function permissionsFor(?Staff $staff): ?array
    {
        if (! $staff || (bool) $staff->is_hitler) {
            return null;
        }

        if ($staff->permissions !== null) {
            return $staff->permissions;
        }

        if ($staff->role_id) {
            return $staff->role?->permissions ?? [];
        }

        return null;
    }

    public static function check(string $class, string $ability, ?Staff $staff = null): ?bool
    {
        $permissions = static::permissionsFor($staff ?? static::user());

        if ($permissions === null) {
            return null;
        }

        return in_array($ability, (array) ($permissions[class_basename($class)] ?? []), true);
    }

    /**
     * Every tab of the panel, in sidebar order: key => [label, group, abilities, class].
     *
     * @return array<string, array{label: string, group: string, abilities: array<string, string>, class: class-string}>
     */
    public static function tabs(): array
    {
        $panel = Filament::getPanel('admin');
        $groups = array_values(array_map(fn ($g) => is_string($g) ? $g : $g->getLabel(), $panel->getNavigationGroups()));
        $tabs = [];

        foreach (array_merge($panel->getResources(), $panel->getPages()) as $class) {
            // A cluster opens when any of its pages does; it isn't a tab of its own.
            if (is_subclass_of($class, \Filament\Clusters\Cluster::class)) {
                continue;
            }

            $isPage = is_subclass_of($class, Page::class);
            $cluster = $class::getCluster();

            $tabs[class_basename($class)] = [
                'label' => $isPage ? ($class::getNavigationLabel() ?: $class::getTitle()) : $class::getNavigationLabel(),
                'group' => $cluster ? ($cluster::getNavigationGroup() ?? 'أخرى').' — '.$cluster::getNavigationLabel() : ($class::getNavigationGroup() ?? 'عام'),
                'abilities' => $isPage ? self::PAGE_ABILITIES : self::ABILITIES,
                'class' => $class,
                'sort' => [$class::getNavigationSort() ?? 0],
            ];
        }

        uasort($tabs, function ($a, $b) use ($groups) {
            $ga = array_search(explode(' — ', $a['group'])[0], $groups, true);
            $gb = array_search(explode(' — ', $b['group'])[0], $groups, true);
            $ga = $a['group'] === 'عام' ? -1 : ($ga === false ? 99 : $ga);
            $gb = $b['group'] === 'عام' ? -1 : ($gb === false ? 99 : $gb);

            return [$ga, $a['group'], $a['sort']] <=> [$gb, $b['group'], $b['sort']];
        });

        return $tabs;
    }

    /** Where the user lands: the dashboard if it's open to him, else the first tab he may open. */
    public static function homeUrl(): ?string
    {
        foreach (static::tabs() as $tab) {
            $class = $tab['class'];

            if ($class === \App\Filament\Pages\Dashboard::class || ! $class::canAccess()) {
                continue;
            }

            return $class::getUrl();
        }

        return null;
    }
}
