<?php

namespace App\Filament\Resources\InstallmentCalculatorResource\Pages;

use App\Filament\Resources\InstallmentCalculatorResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInstallmentCalculators extends ListRecords
{
    protected static string $resource = InstallmentCalculatorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
