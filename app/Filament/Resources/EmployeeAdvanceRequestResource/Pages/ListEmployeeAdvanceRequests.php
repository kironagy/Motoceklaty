<?php

namespace App\Filament\Resources\EmployeeAdvanceRequestResource\Pages;

use App\Filament\Resources\EmployeeAdvanceRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeAdvanceRequests extends ListRecords
{
    protected static string $resource = EmployeeAdvanceRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
