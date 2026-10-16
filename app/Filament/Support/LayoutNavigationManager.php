<?php

namespace App\Filament\Support;

use Filament\Navigation\NavigationManager;

/**
 * Filament's sidebar builder, with every item renamed / moved / ordered by the owner's layout
 * (NavigationLayout). Visibility, badges and links stay Filament's own.
 */
class LayoutNavigationManager extends NavigationManager
{
    public function mountNavigation(): void
    {
        $placements = $this->panel->getId() === 'admin' ? NavigationLayout::placements() : [];

        foreach (array_merge($this->panel->getPages(), $this->panel->getResources()) as $class) {
            $before = count($this->navigationItems);

            $class::registerNavigationItems();

            if ($placements) {
                foreach (array_slice($this->navigationItems, $before) as $item) {
                    NavigationLayout::apply($item, class_basename($class), $placements);
                }
            }
        }

        if ($placements) {
            $this->navigationGroups = NavigationLayout::groupOrder($this->navigationGroups);
        }

        $this->isNavigationMounted = true;
    }
}
