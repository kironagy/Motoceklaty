<?php

namespace App\Filament\Resources\GeminiApiKeyResource\Pages;

use App\Filament\Resources\GeminiApiKeyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGeminiApiKeys extends ListRecords
{
    protected static string $resource = GeminiApiKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
