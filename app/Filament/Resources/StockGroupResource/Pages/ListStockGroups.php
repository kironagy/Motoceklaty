<?php

namespace App\Filament\Resources\StockGroupResource\Pages;

use App\Filament\Resources\StockGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStockGroups extends ListRecords
{
    protected static string $resource = StockGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
