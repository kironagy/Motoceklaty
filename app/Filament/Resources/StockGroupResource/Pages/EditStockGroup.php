<?php

namespace App\Filament\Resources\StockGroupResource\Pages;

use App\Filament\Resources\StockGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStockGroup extends EditRecord
{
    protected static string $resource = StockGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
