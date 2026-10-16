<?php

namespace App\Filament\Resources\TreasuryExpenseResource\Pages;

use App\Filament\Resources\TreasuryExpenseResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTreasuryExpense extends EditRecord
{
    protected static string $resource = TreasuryExpenseResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return TreasuryExpenseResource::mutateFormDataBeforeSave($data);
    }

    protected function afterSave(): void
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

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
