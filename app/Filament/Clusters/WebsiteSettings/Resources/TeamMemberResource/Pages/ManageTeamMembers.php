<?php

namespace App\Filament\Clusters\WebsiteSettings\Resources\TeamMemberResource\Pages;

use App\Filament\Clusters\WebsiteSettings\Resources\TeamMemberResource;
use App\Models\TeamMember;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTeamMembers extends ManageRecords
{
    protected static string $resource = TeamMemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateFormDataUsing(fn (array $data) => $data + ['sort' => (int) TeamMember::max('sort') + 1]),
        ];
    }
}
