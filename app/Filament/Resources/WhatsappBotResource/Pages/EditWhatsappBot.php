<?php

namespace App\Filament\Resources\WhatsappBotResource\Pages;

use App\Filament\Resources\WhatsappBotResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWhatsappBot extends EditRecord
{
    protected static string $resource = WhatsappBotResource::class;

    /** Owner 2026-11-15: a changed proxy goes to the worker right away (it reconnects through it). */
    protected function afterSave(): void
    {
        if ($this->record->wasChanged('proxy_url')) {
            WhatsappBotResource::pushProxy($this->record);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            // Deactivate (is_active) instead: deleting a bot with customers
            // would cascade to all their applications and documents.
            Actions\DeleteAction::make()
                ->hidden(fn () => $this->record->hasCustomerData()),
        ];
    }
}
