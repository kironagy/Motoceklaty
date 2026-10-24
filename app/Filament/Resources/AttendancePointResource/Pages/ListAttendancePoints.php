<?php

namespace App\Filament\Resources\AttendancePointResource\Pages;

use App\Filament\Resources\AttendancePointResource;
use Filament\Resources\Pages\ListRecords;

class ListAttendancePoints extends ListRecords
{
    protected static string $resource = AttendancePointResource::class;

    public function getTabs(): array
    {
        return [
            'summary' => \Filament\Resources\Components\Tab::make('إجمالي نقط الموظفين'),
            'history' => \Filament\Resources\Components\Tab::make('سجل النقط والتفاصيل'),
        ];
    }

    public function getDefaultActiveTab(): string { return 'summary'; }

    protected function getHeaderActions(): array
    {
        return [
            AttendancePointResource::manualPointsAction(),
            AttendancePointResource::greensToBonusAction(),
        ];
    }
}
