<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

/**
 * Everything that shapes the public website, in one sidebar entry:
 * per-page content (texts, images, lists) plus the website-only records
 * (articles, team, testimonials, contact messages, subscribers).
 */
class WebsiteSettings extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Website Settings';

    protected static ?string $clusterBreadcrumb = 'Website Settings';

    protected static ?int $navigationSort = 1;
}
