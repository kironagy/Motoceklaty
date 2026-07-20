<?php

namespace App\Filament\Resources\TraderResource\Pages;

use App\Filament\Resources\TraderResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTraders extends ListRecords
{
    protected static string $resource = TraderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
