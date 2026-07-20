<?php

namespace App\Filament\Resources\InstallmentSystemResource\Pages;

use App\Filament\Resources\InstallmentSystemResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInstallmentSystems extends ListRecords
{
    protected static string $resource = InstallmentSystemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
