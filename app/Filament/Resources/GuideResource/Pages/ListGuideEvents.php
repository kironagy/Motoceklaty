<?php

namespace App\Filament\Resources\GuideResource\Pages;

use App\Filament\Resources\GuideResource;
use Filament\Resources\Pages\ListRecords;

class ListGuideEvents extends ListRecords
{
    protected static string $resource = GuideResource::class;

    public function getTabs(): array
    {
        return [
            'changes' => \Filament\Resources\Components\Tab::make('تغييرات الموظفين')->modifyQueryUsing(fn ($query) => $query->whereIn('event', ['created', 'updated', 'deleted', 'restored'])),
            'access' => \Filament\Resources\Components\Tab::make('الدخول والخروج')->modifyQueryUsing(fn ($query) => $query->whereIn('event', ['login', 'logout'])),
            'all' => \Filament\Resources\Components\Tab::make('كل تحركات الأشخاص'),
        ];
    }

    public function getDefaultActiveTab(): string { return 'changes'; }

    public function getSubheading(): ?string
    {
        return 'اعرف الموظف غيّر إيه بالضبط: إضافة، تعديل، حذف أو دخول. تغييرات الأشخاص فقط، والسجل القديم محفوظ.';
    }

    protected function getHeaderWidgets(): array
    {
        return [\App\Filament\Resources\GuideResource\Widgets\GuideOverview::class];
    }
}
