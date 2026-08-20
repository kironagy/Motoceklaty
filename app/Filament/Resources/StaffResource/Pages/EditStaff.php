<?php

namespace App\Filament\Resources\StaffResource\Pages;

use App\Filament\Resources\StaffResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStaff extends EditRecord
{
    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
    
    protected function mutateFormDataBeforeSave(array $data): array
{
    $user = auth()->user();

    if (! $user?->is_super_admin || $this->record->id === $user?->id) {
        unset(
            $data['is_admin'],
            $data['is_super_admin'],
            $data['is_bot'],
            $data['is_company_employee'],
            $data['installmentSystems'],
        );
    }

    return $data;
}
}
