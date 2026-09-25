<?php

namespace App\Filament\Resources\ApplicationRequirementResource\Pages;

use App\Filament\Resources\ApplicationRequirementResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditApplicationRequirement extends EditRecord
{
    protected static string $resource = ApplicationRequirementResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (empty($data['condition']['fact'])) {
            $data['condition'] = null;
        }

        return $data;
    }
}
