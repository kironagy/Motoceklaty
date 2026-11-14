<?php

namespace App\Filament\Resources\EmployeeDeductionResource\Pages;

use App\Filament\Resources\EmployeeDeductionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeDeductions extends ListRecords
{
    protected static string $resource = EmployeeDeductionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('إضافة خصم'),
        ];
    }
}
