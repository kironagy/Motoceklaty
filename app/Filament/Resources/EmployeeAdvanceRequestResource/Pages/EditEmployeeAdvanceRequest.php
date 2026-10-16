<?php

namespace App\Filament\Resources\EmployeeAdvanceRequestResource\Pages;

use App\Filament\Resources\EmployeeAdvanceRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEmployeeAdvanceRequest extends EditRecord
{
    protected static string $resource = EmployeeAdvanceRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
