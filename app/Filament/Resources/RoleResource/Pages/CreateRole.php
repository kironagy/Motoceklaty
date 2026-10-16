<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use App\Filament\Support\PermissionMatrix;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected static ?string $title = 'دور جديد';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['permissions'] = PermissionMatrix::clean($data['permissions'] ?? []);

        return $data;
    }
}
