<?php

namespace App\Filament\Resources\AiMemoryResource\Pages;

use App\Filament\Resources\AiMemoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAiMemories extends ListRecords
{
    protected static string $resource = AiMemoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
