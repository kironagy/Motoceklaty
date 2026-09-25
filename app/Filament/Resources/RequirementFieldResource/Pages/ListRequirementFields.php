<?php

namespace App\Filament\Resources\RequirementFieldResource\Pages;

use App\Filament\Resources\RequirementFieldResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRequirementFields extends ListRecords
{
    protected static string $resource = RequirementFieldResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
