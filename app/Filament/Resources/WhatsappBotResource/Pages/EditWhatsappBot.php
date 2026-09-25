<?php

namespace App\Filament\Resources\WhatsappBotResource\Pages;

use App\Filament\Resources\WhatsappBotResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWhatsappBot extends EditRecord
{
    protected static string $resource = WhatsappBotResource::class;

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
