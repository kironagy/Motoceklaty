<?php

namespace App\Filament\Resources\InstallmentCalculatorResource\Pages;

use App\Filament\Resources\InstallmentCalculatorResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInstallmentCalculator extends EditRecord
{
    protected static string $resource = InstallmentCalculatorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
