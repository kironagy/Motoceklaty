<?php

namespace App\Filament\Resources\BotOfferResource\Pages;

use App\Filament\Resources\BotOfferResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBotOffer extends EditRecord
{
    protected static string $resource = BotOfferResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
