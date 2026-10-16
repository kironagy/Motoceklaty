<?php

namespace App\Filament\Resources\AttendancePointResource\Pages;

use App\Filament\Resources\AttendancePointResource;
use Filament\Resources\Pages\ListRecords;

class ListAttendancePoints extends ListRecords
{
    protected static string $resource = AttendancePointResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AttendancePointResource::manualPointsAction(),
            AttendancePointResource::greensToBonusAction(),
        ];
    }
}
