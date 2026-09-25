<?php

namespace App\Filament\Resources\ApplicationRequirementResource\Pages;

use App\Filament\Resources\ApplicationRequirementResource;
use Filament\Resources\Pages\CreateRecord;

class CreateApplicationRequirement extends CreateRecord
{
    protected static string $resource = ApplicationRequirementResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['condition']['fact'])) {
            $data['condition'] = null;
        }

        return $data;
    }
}
