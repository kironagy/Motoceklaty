<?php

namespace App\Filament\Widgets;

use App\Models\Target;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TargetStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '30s';

    protected function getColumns(): int
    {
        return 3;
    }

    public static function canView(): bool
    {
        return \App\Support\Access::allows(\App\Filament\Resources\TargetResource::class, 'view');
    }

    protected function getStats(): array
    {
        $user = auth()->user();

        $targets = Target::query()
            ->where('is_active', true)
            ->when(
                ! \App\Support\Access::full(\App\Filament\Resources\TargetResource::class, $user),
                fn ($q) => $q->where('warehouse_id', $user->warehouse_id)
            )
            ->with('warehouse')
            ->get();

        return $targets->map(function (Target $target) {
            $sold = $target->soldCount();
            $targetCount = max((int) $target->target_count, 1);
            $percent = min(100, round(($sold / $targetCount) * 100));

            return Stat::make(
                $target->warehouse?->name ?? 'مخزن',
                $sold . ' / ' . $targetCount
            )
                ->description("تم تحقيق {$percent}% من التارجت")
                ->descriptionIcon(
                    $sold >= $targetCount
                        ? 'heroicon-m-trophy'
                        : 'heroicon-m-fire'
                )
                ->icon(
                    $sold >= $targetCount
                        ? 'heroicon-o-trophy'
                        : 'heroicon-o-flag'
                )
                ->color($sold >= $targetCount ? 'success' : 'warning')
                ->extraAttributes([
                    'class' => '
                        relative overflow-hidden rounded-3xl
                        bg-gradient-to-br from-slate-900 via-slate-800 to-slate-950
                        border border-white/10 shadow-2xl
                        hover:scale-[1.02] transition-all duration-300
                        [&_.fi-wi-stats-overview-stat-label]:text-white
                        [&_.fi-wi-stats-overview-stat-value]:text-5xl
                        [&_.fi-wi-stats-overview-stat-value]:font-black
                        [&_.fi-wi-stats-overview-stat-value]:text-white
                        [&_.fi-wi-stats-overview-stat-description]:text-gray-300
                    ',
                ]);
        })->toArray();
    }
}
