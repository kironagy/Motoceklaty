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
    protected static ?string $navigationGroup = 'الموقع الإلكتروني';

    protected static ?string $navigationLabel = 'إعدادات الموقع';

    protected static ?string $clusterBreadcrumb = 'إعدادات الموقع';

    protected static ?int $navigationSort = 1;
}
