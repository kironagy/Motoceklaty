<?php

namespace App\Filament\Resources\ApplicationRequirementResource\Pages;

use App\Filament\Resources\ApplicationRequirementResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListApplicationRequirements extends ListRecords
{
    protected static string $resource = ApplicationRequirementResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
