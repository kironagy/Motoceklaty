<?php

namespace App\Filament\Resources\LegacyStatusMappingResource\Pages;

use App\Filament\Resources\LegacyStatusMappingResource;
use Filament\Resources\Pages\ListRecords;

class ListLegacyStatusMappings extends ListRecords
{
    protected static string $resource = LegacyStatusMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
