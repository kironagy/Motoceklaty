<?php

namespace App\Filament\Clusters\WebsiteSettings\Resources\PostResource\Pages;

use App\Filament\Clusters\WebsiteSettings\Resources\PostResource;
use Filament\Resources\Pages\ListRecords;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
