<?php

namespace App\Filament\Resources\TreasuryExpenseResource\Pages;

use App\Filament\Resources\TreasuryExpenseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTreasuryExpense extends CreateRecord
{
    protected static string $resource = TreasuryExpenseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return TreasuryExpenseResource::mutateFormDataBeforeCreate($data);
    }

    protected function afterCreate(): void
    {
        foreach ($this->record->items as $item) {
            if (! $item->created_by) {
                $item->update(['created_by' => auth()->id()]);
            }
        }

        $this->record->update([
            'total_amount' => $this->record->items()->sum('amount'),
        ]);
    }
}
