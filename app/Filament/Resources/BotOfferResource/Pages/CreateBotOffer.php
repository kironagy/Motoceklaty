<?php

namespace App\Filament\Resources\BotOfferResource\Pages;

use App\Filament\Resources\BotOfferResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBotOffer extends CreateRecord
{
    protected static string $resource = BotOfferResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
