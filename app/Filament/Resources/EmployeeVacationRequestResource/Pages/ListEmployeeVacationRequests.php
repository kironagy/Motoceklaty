<?php

namespace App\Filament\Resources\EmployeeVacationRequestResource\Pages;

use App\Filament\Resources\EmployeeVacationRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeVacationRequests extends ListRecords
{
    protected static string $resource = EmployeeVacationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
