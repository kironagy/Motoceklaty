<?php

namespace App\Filament\Resources\InstallmentCalculatorResource\Pages;

use App\Filament\Resources\InstallmentCalculatorResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInstallmentCalculator extends CreateRecord
{
    protected static string $resource = InstallmentCalculatorResource::class;

    protected function getCreateFormAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('hidden')
            ->hidden();
    }

    protected function getCreateAnotherFormAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('hidden')
            ->hidden();
    }

    protected function getCancelFormAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('hidden')
            ->hidden();
    }

    protected function getFormActions(): array
    {
        return [];
    }

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        // منع حفظ أي عملية حساب
        return new \App\Models\InstallmentCalculator();
    }
}
