<?php

namespace App\Filament\Resources\DailyTaskReportResource\Pages;

use App\Filament\Resources\DailyTaskReportResource;
use Filament\Resources\Pages\ViewRecord;

class ViewDailyTaskReport extends ViewRecord
{
    protected static string $resource = DailyTaskReportResource::class;

    protected static string $view = 'filament.resources.daily-tasks.report';

    public function getTitle(): string
    {
        return 'تقرير '.$this->record->team?->name.' - '.$this->record->report_date->format('d/m/Y');
    }
}
