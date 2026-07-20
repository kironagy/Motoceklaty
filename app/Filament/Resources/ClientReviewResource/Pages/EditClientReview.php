<?php

namespace App\Filament\Resources\ClientReviewResource\Pages;

use App\Filament\Resources\ClientReviewResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditClientReview extends EditRecord
{
    protected static string $resource = ClientReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
