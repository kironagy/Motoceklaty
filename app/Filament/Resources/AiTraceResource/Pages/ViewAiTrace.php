<?php

namespace App\Filament\Resources\AiTraceResource\Pages;

use App\Filament\Resources\AiTraceResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAiTrace extends ViewRecord
{
    protected static string $resource = AiTraceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
