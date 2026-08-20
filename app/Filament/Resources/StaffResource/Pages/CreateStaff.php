<?php

namespace App\Filament\Resources\StaffResource\Pages;

use App\Filament\Resources\StaffResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateStaff extends CreateRecord
{
    protected static string $resource = StaffResource::class;
    protected function mutateFormDataBeforeCreate(array $data): array
{
    if (! auth()->user()?->is_super_admin) {
        $data['is_admin'] = false;
        $data['is_super_admin'] = false;
        $data['is_bot'] = false;
        $data['is_company_employee'] = false;
        $data['installmentSystems'] = [];
    }

    return $data;
}
}
