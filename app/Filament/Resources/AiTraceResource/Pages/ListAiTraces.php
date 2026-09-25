<?php

namespace App\Filament\Resources\AiTraceResource\Pages;

use App\Filament\Resources\AiTraceResource;
use Filament\Resources\Pages\ListRecords;

class ListAiTraces extends ListRecords
{
    protected static string $resource = AiTraceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
