<?php

namespace App\Filament\Clusters\WebsiteSettings\Resources\PostResource\Pages;

use App\Filament\Clusters\WebsiteSettings\Resources\PostResource;
use Filament\Resources\Pages\EditRecord;

class EditPost extends EditRecord
{
    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
