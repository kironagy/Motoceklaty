<?php

namespace App\Filament\Resources\AmanExtensionControlResource\Pages;

use App\Filament\Resources\AmanExtensionControlResource;
use App\Filament\Resources\AmanExtensionControlResource\Widgets\AmanDailyReport;
use Filament\Resources\Pages\ListRecords;

class ListAmanExtensionControls extends ListRecords
{
    protected static string $resource = AmanExtensionControlResource::class;

    // Owner 2026-11-20: the daily report above the switches
    protected function getHeaderWidgets(): array
    {
        return [AmanDailyReport::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
