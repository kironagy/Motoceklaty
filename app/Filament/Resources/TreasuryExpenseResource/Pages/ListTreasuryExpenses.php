<?php

namespace App\Filament\Resources\TreasuryExpenseResource\Pages;

use App\Filament\Resources\TreasuryExpenseResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTreasuryExpenses extends ListRecords
{
    protected static string $resource = TreasuryExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
