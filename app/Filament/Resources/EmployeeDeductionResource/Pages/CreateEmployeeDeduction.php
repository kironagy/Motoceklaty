<?php

namespace App\Filament\Resources\EmployeeDeductionResource\Pages;

use App\Filament\Resources\EmployeeDeductionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployeeDeduction extends CreateRecord
{
    protected static string $resource = EmployeeDeductionResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
