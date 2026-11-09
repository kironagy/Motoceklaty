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
            Actions\CreateAction::make()->label('بوت جديد')->icon('heroicon-o-plus'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [WhatsappBotResource\Widgets\BotsTodayOverview::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
