<?php

namespace App\Filament\Resources\EmployeeVacationRequestResource\Pages;

use App\Filament\Resources\EmployeeVacationRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEmployeeVacationRequest extends EditRecord
{
    protected static string $resource = EmployeeVacationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
