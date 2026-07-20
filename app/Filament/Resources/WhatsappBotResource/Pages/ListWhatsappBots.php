<?php

namespace App\Filament\Resources\WhatsappBotResource\Pages;

use App\Filament\Resources\WhatsappBotResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWhatsappBots extends ListRecords
{
    protected static string $resource = WhatsappBotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
