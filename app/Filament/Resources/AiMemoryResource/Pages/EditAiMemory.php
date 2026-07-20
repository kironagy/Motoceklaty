<?php

namespace App\Filament\Resources\AiMemoryResource\Pages;

use App\Filament\Resources\AiMemoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAiMemory extends EditRecord
{
    protected static string $resource = AiMemoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
