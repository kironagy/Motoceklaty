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

    /** Owner 2026-11-27: say what happened with the customer's «جوابك جاهز» message. */
    protected function afterSave(): void
    {
        $letter = $this->record;
        if ($letter->status !== \App\Jobs\SendLetterReadyWhatsapp::READY_STATUS || $letter->ready_sent_at) {
            return;
        }
        if (blank($letter->letter_image)) {
            \Filament\Notifications\Notification::make()->title('ما اتبعتش حاجة للعميل')->body('ارفع «صورة الجواب» عشان تتبعتله على الواتساب إن جوابه جاهز.')->warning()->send();

            return;
        }
        if ($letter->wasChanged('status') || $letter->wasChanged('letter_image')) {
            \Filament\Notifications\Notification::make()->title('صورة الجواب هتتبعت للعميل على الواتساب')->success()->send();
        }
    }
}
