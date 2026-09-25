<?php

namespace App\Filament\Resources\LegacyStatusMappingResource\Pages;

use App\Filament\Resources\LegacyStatusMappingResource;
use Filament\Resources\Pages\EditRecord;

class EditLegacyStatusMapping extends EditRecord
{
    protected static string $resource = LegacyStatusMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
