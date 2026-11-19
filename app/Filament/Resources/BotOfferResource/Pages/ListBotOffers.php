<?php

namespace App\Filament\Resources\BotOfferResource\Pages;

use App\Filament\Resources\BotOfferResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBotOffers extends ListRecords
{
    protected static string $resource = BotOfferResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('عرض جديد')];
    }
}
