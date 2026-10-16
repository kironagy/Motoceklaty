<?php

namespace App\Filament\Resources\TargetResource\Pages;

use App\Filament\Resources\TargetResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTargets extends ListRecords
{
    protected static string $resource = TargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
