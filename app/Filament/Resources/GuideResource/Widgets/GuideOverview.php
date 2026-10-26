<?php

namespace App\Filament\Resources\GuideResource\Widgets;

use App\Filament\Resources\GuideResource;
use App\Models\GuideEvent;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class GuideOverview extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = '15s';

    public static function canView(): bool { return GuideResource::canViewAny(); }

    protected function getStats(): array
    {
        abort_unless(GuideResource::canViewAny(), 403);
        $start = now('Africa/Cairo')->startOfDay()->setTimezone(config('app.timezone'));
        $today = GuideEvent::query()->people()->where('created_at', '>=', $start);

        return [
            Stat::make('عمليات الموظفين اليوم', (clone $today)->count())->description('بدون حركة السيرفر والبوتات')->descriptionIcon('heroicon-o-bolt')->color('primary'),
            Stat::make('المشاركون اليوم', (clone $today)->whereNotNull('actor_id')->select('actor_type', 'actor_id')->distinct()->get()->count())->description('مستخدمون نفذوا عمليات')->descriptionIcon('heroicon-o-users')->color('info'),
            Stat::make('تعديلات اليوم', (clone $today)->where('event', 'updated')->count())->description('تفاصيل قبل وبعد لكل تعديل')->descriptionIcon('heroicon-o-pencil-square')->color('warning'),
            Stat::make('عمليات الحذف', (clone $today)->where('event', 'deleted')->count())->description('عمليات الحذف المسجلة اليوم')->descriptionIcon('heroicon-o-trash')->color('danger'),
        ];
    }
}
