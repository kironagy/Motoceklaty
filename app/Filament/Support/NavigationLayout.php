<?php

namespace App\Filament\Support;

use App\Models\DashboardSetting;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;

/**
 * The sidebar as the owner arranged it on the dashboard settings page: group names and order, and
 * which tabs sit in each group, in what order, under what name. Saved as
 * {groups: [{label, items: [{key, label}]}]}; a tab key is its class basename (as in the roles matrix).
 * Tabs the layout doesn't mention (added later) keep their own group and place.
 */
class NavigationLayout
{
    public const KEY = 'navigation';

    public static function saved(): ?array
    {
        $layout = DashboardSetting::get(self::KEY);

        return is_array($layout) && ! empty($layout['groups']) ? $layout : null;
    }

    /**
     * Every class that can show in the sidebar: key => [label, group, sort, icon].
     *
     * @return array<string, array{label: string, group: ?string, sort: int}>
     */
    public static function entries(): array
    {
        $panel = Filament::getPanel('admin');
        $entries = [];

        foreach (array_merge($panel->getPages(), $panel->getResources()) as $class) {
            // Pages inside a cluster show in the cluster's own menu, not the sidebar.
            if ($class::getCluster() !== null) {
                continue;
            }

            // Tabs kept out of the sidebar on purpose (or closed to the editing user) aren't listed.
            if (! $class::shouldRegisterNavigation()) {
                continue;
            }

            $entries[class_basename($class)] = [
                'label' => trim((string) $class::getNavigationLabel()),
                'group' => $class::getNavigationGroup(),
                'sort' => (int) ($class::getNavigationSort() ?? 0),
                'class' => $class,
            ];
        }

        return $entries;
    }

    /**
     * For the sidebar builder: key => [label, icon svg, group] so the live preview looks like the real sidebar.
     *
     * @return array<string, array{label: string, icon: string, group: string}>
     */
    public static function builderEntries(): array
    {
        return collect(static::entries())->map(function ($e, $key) {
            $icon = $e['class']::getNavigationIcon();

            try {
                $svg = svg(is_string($icon) && $icon !== '' ? $icon : 'heroicon-o-square-3-stack-3d', 'sb-svg')->toHtml();
            } catch (\Throwable) {
                $svg = svg('heroicon-o-square-3-stack-3d', 'sb-svg')->toHtml();
            }

            return ['label' => $e['label'] ?: $key, 'icon' => $svg, 'group' => (string) ($e['group'] ?? '')];
        })->all();
    }

    /** The current sidebar as a layout, to start the editor from. */
    public static function current(): array
    {
        if ($saved = static::saved()) {
            return static::withNewTabs($saved);
        }

        $registered = array_values(array_map(fn ($g) => is_string($g) ? $g : $g->getLabel(), Filament::getPanel('admin')->getNavigationGroups()));

        $entries = collect(static::entries())
            ->map(fn ($e, $key) => $e + ['key' => $key])
            ->sortBy(fn ($e) => [
                $e['group'] === null ? -1 : (array_search($e['group'], $registered, true) === false ? 99 : array_search($e['group'], $registered, true)),
                $e['sort'],
            ]);

        return ['groups' => $entries
            ->groupBy(fn ($e) => $e['group'] ?? '')
            ->map(fn ($items, $group) => [
                'label' => $group,
                'items' => $items->map(fn ($e) => ['key' => $e['key'], 'label' => null])->values()->all(),
            ])
            ->values()
            ->all()];
    }

    /** A saved layout plus the tabs added since, at the end of their own group (or a new one). */
    protected static function withNewTabs(array $layout): array
    {
        $placed = collect($layout['groups'])->flatMap(fn ($g) => array_column($g['items'] ?? [], 'key'))->all();

        foreach (static::entries() as $key => $entry) {
            if (in_array($key, $placed, true)) {
                continue;
            }

            $index = collect($layout['groups'])->search(fn ($g) => ($g['label'] ?? '') === ($entry['group'] ?? ''));

            if ($index === false) {
                $layout['groups'][] = ['label' => $entry['group'] ?? '', 'items' => []];
                $index = array_key_last($layout['groups']);
            }

            $layout['groups'][$index]['items'][] = ['key' => $key, 'label' => null];
        }

        return $layout;
    }

    /** key => [label, group, sort] from the saved layout. */
    public static function placements(): array
    {
        $placements = [];

        foreach (static::saved()['groups'] ?? [] as $g) {
            foreach ($g['items'] ?? [] as $i => $item) {
                $placements[$item['key']] = [
                    'label' => filled($item['label'] ?? null) ? trim($item['label']) : null,
                    'group' => filled($g['label'] ?? null) ? trim($g['label']) : null,
                    'sort' => $i,
                ];
            }
        }

        return $placements;
    }

    /** The tab's name as the owner set it, else its own. */
    public static function labelFor(string $key, string $default): string
    {
        return static::placements()[$key]['label'] ?? $default;
    }

    /** Renames, moves and orders a sidebar item that the given class registered. */
    public static function apply(NavigationItem $item, string $key, array $placements): void
    {
        if (! isset($placements[$key])) {
            return;
        }

        $place = $placements[$key];

        if ($place['label']) {
            $item->label($place['label']);
        }

        $item->group($place['group']);
        $item->sort($place['sort']);
    }

    /** Group order: the layout's groups first, in its order, then any other group. */
    public static function groupOrder(array $registered): array
    {
        $layout = collect(static::saved()['groups'] ?? [])
            ->pluck('label')
            ->filter()
            ->map(fn ($l) => trim($l))
            ->values()
            ->all();

        $others = array_values(array_filter(
            array_map(fn ($g) => is_string($g) ? $g : $g->getLabel(), $registered),
            fn ($g) => ! in_array($g, $layout, true),
        ));

        return array_values(array_unique(array_merge($layout, $others)));
    }
}
