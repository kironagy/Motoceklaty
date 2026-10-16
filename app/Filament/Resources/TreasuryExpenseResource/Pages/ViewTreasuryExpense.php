<?php

namespace App\Filament\Resources\TreasuryExpenseResource\Pages;

use App\Filament\Resources\TreasuryExpenseResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewTreasuryExpense extends ViewRecord
{
    protected static string $resource = TreasuryExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
