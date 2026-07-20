<?php

namespace App\Filament\Resources\GeminiApiKeyResource\Pages;

use App\Filament\Resources\GeminiApiKeyResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditGeminiApiKey extends EditRecord
{
    protected static string $resource = GeminiApiKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
