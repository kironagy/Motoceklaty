<?php

namespace App\Filament\Resources\LetterResource\Pages;

use App\Filament\Resources\LetterResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLetter extends EditRecord
{
    protected static string $resource = LetterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
    protected function getFormActions(): array
{
    return [
        ...parent::getFormActions(),

        \Filament\Actions\Action::make('print_sale')
            ->label('طباعة المبايعة')
            ->icon('heroicon-o-printer')
            ->color('warning')
            ->url(fn () => route('letters.print-sale', $this->record))
            ->openUrlInNewTab(),
    ];
}
}
