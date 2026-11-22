<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\DeliveryResource;
use App\Models\InstallmentRequest;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Filament\Support\Facades\FilamentView;

class RequestsStatusOverview extends BaseWidget
{
    protected static ?int $sort = -2;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $pollingInterval = '120s';

    protected function getColumns(): int
    {
        return 3;
    }

    public static function canView(): bool
    {
        $user = Auth::user();

        return (bool) (
            $user?->is_super_admin ||
            $user?->role === 'super_admin'
        );
    }

    protected function getStats(): array
    {
        // the card look lives in public/css/filament/admin/stats-cards.css (owner 2026-11-22)

        // One grouped query (was 11 full-table counts), shared by every viewer for a minute.
        // A company (Aman) employee counts only his companies' requests (owner 2026-11-16).
        $user = Auth::user();
        $companies = $user?->isAmaanRestricted() ? ($user->companyNames() ?: ['__none__']) : null;
        // Owner 2026-11-18: fake requests are not counted (108 of the 110 "طلب جديد" were fake, and
        // the requests list opens on the main requests) - they have their own tab.
        $scope = fn ($q) => $q->where('request_type', '!=', 'fake')
            ->when($companies !== null, fn ($q) => $q->whereIn('installment_type', $companies));

        $counts = Cache::remember('dashboard:requests-status-counts:v2'.($companies !== null ? ':'.md5(implode('|', $companies)) : ''), now()->addMinute(), function () use ($scope): array {
            $byStatus = InstallmentRequest::query()->tap($scope)
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->map(fn ($count) => (int) $count)
                ->all();

            $byStatus['__total'] = array_sum($byStatus);
            $byStatus['__new_normal'] = InstallmentRequest::query()->tap($scope)
                ->where('status', 'new')
                ->where('request_type', 'normal')
                ->count();

            return $byStatus;
        });

        $total = $counts['__total'] ?? 0;
        $new = $counts['__new_normal'] ?? 0;
        $newRequest = $counts['new_request'] ?? 0;
        $pending = $counts['pending'] ?? 0;
        $workCheck = $counts['work_check'] ?? 0;
        $approved = $counts['approved'] ?? 0;
        $rejected = $counts['rejected'] ?? 0;
        $paused = $counts['paused'] ?? 0;
        $pausedAman = $counts['paused_aman'] ?? 0;
        $edited = $counts['edited'] ?? 0;
        $transferred = $counts['transferred'] ?? 0;
        $delivered = $counts['delivered'] ?? 0;
        $canceled = $counts['canceled'] ?? 0;

        return [
            $this->card('إجمالي الطلبات', $total, 'كل الطلبات من غير الفيك', 'heroicon-o-clipboard-document-list', 'total', [2, 5, 8, 6, 12, 10, 15], []),

            $this->card('انتظار', $new, 'طلبات في الانتظار', 'heroicon-o-clock', 'waiting', null, ['new'], 'normal'),
            $this->card('طلب جديد', $newRequest, 'طلبات جديدة', 'heroicon-o-sparkles', 'new-request', null, ['new_request']),
            $this->card('تحت الاستعلام', $pending, 'طلبات تحت الاستعلام', 'heroicon-o-magnifying-glass-circle', 'pending', null, ['pending']),

            $this->card('استعلام عمل', $workCheck, 'طلبات استعلام العمل', 'heroicon-o-briefcase', 'work-check', null, ['work_check']),
            $this->card('موافقة', $approved, 'طلبات تمت الموافقة عليها', 'heroicon-o-check-circle', 'approved', null, ['approved']),
            $this->card('رفض', $rejected, 'طلبات تم رفضها', 'heroicon-o-x-circle', 'rejected', null, ['rejected']),

            $this->card('متوقف', $paused, 'طلبات موقوفة مؤقتًا', 'heroicon-o-pause-circle', 'paused', null, ['paused']),
            $this->card('متوقف امان', $pausedAman, 'طلبات متوقفة عند أمان', 'heroicon-o-pause-circle', 'paused-aman', null, ['paused_aman']),
            $this->card('تم التعديل', $edited, 'طلبات اتعدلت', 'heroicon-o-pencil-square', 'edited', null, ['edited']),
            $this->card('محول', $transferred, 'طلبات تم تحويلها', 'heroicon-o-arrow-path-rounded-square', 'transferred', null, ['transferred']),
            $this->card('استلم المكنة', $delivered, 'طلبات تم تسليمها', 'heroicon-o-truck', 'delivered', null, ['delivered']),

            $this->card('الطلب ملغي', $canceled, 'طلبات تم إلغاؤها', 'heroicon-o-trash', 'canceled', null, ['canceled']),
        ];
    }

    protected function card(
        string $label,
        int $value,
        string $description,
        string $icon,
        string $type,
        ?array $chart = null,
        ?array $statuses = null,
        string $tab = 'real',
    ): Stat {
        $stat = Stat::make($label, number_format($value))
            ->description($description)
            ->descriptionIcon($icon)
            ->extraAttributes([
                'class' => "requests-status-card requests-status-card-{$type}",
            ]);

        if ($chart) {
            $stat->chart($chart);
        }

        // Owner 2026-11-19: a card opens the requests list filtered on the status it counts
        // ("انتظار" counts the main requests only, the rest every request but the fake ones).
        if ($statuses !== null) {
            $stat->url(DeliveryResource::getUrl('index', array_filter([
                'activeTab' => $tab,
                'tableFilters' => $statuses !== [] ? ['status' => ['values' => $statuses]] : null,
            ])));
        }

        return $stat;
    }
}
