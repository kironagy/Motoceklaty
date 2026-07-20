<?php

namespace App\Filament\Widgets;

use App\Models\InstallmentRequest;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Filament\Support\Facades\FilamentView;

class RequestsStatusOverview extends BaseWidget
{
    protected static ?int $sort = -2;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $pollingInterval = '30s';

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
        FilamentView::registerRenderHook(
            'panels::head.end',
            fn () => <<<HTML
<style>
.fi-wi-stats-overview {
    direction: rtl !important;
}

.fi-wi-stats-overview .grid {
    align-items: stretch !important;
}

.requests-status-card {
    min-height: 210px !important;
    border-radius: 28px !important;
    background: linear-gradient(145deg,#ffffff,#f1f5f9) !important;
    box-shadow: 0 20px 50px rgba(15,23,42,.14) !important;
    border: 1px solid rgba(148,163,184,.28) !important;
    position: relative !important;
    overflow: hidden !important;
    transition: .25s ease !important;
}

.requests-status-card::before {
    content:"" !important;
    position:absolute !important;
    top:0 !important;
    right:0 !important;
    left:0 !important;
    height:8px !important;
    background:linear-gradient(90deg,#4311A5,#A576FF,#30D9EF) !important;
}

.requests-status-card:hover {
    transform: translateY(-5px) !important;
    box-shadow: 0 30px 70px rgba(67,17,165,.22) !important;
}

.requests-status-card-total {
    grid-column: 1 / -1 !important;
    min-height: 240px !important;
    background:
        radial-gradient(circle at 12% 20%, rgba(48,217,239,.22), transparent 30%),
        radial-gradient(circle at 90% 10%, rgba(165,118,255,.25), transparent 34%),
        linear-gradient(135deg,#101827,#1C293E) !important;
}




.requests-status-card-total::before {
    background: linear-gradient(90deg,#0f172a,#334155,#64748b) !important;
}
/* إجمالي الطلبات */
.requests-status-card-total::before{
    background:linear-gradient(90deg,#0f172a,#334155,#64748b)!important;
}

/* انتظار */
.fi-wi-stats-overview .requests-status-card-waiting::before {
    background:linear-gradient(90deg,#f97316,#fdba74) !important;
}

.fi-wi-stats-overview .requests-status-card-new-request::before {
    background:linear-gradient(90deg,#2563eb,#7dd3fc) !important;
}

/* تحت الاستعلام */
.requests-status-card-pending::before{
    background:linear-gradient(90deg,#eab308,#fde68a)!important;
}

/* استعلام عمل */
.requests-status-card-work-check::before{
    background:linear-gradient(90deg,#0f766e,#5eead4)!important;
}

/* موافقة */
.requests-status-card-approved::before{
    background:linear-gradient(90deg,#16a34a,#86efac)!important;
}

/* رفض */
.requests-status-card-rejected::before{
    background:linear-gradient(90deg,#dc2626,#fca5a5)!important;
}

/* متوقف */
.requests-status-card-paused::before{
    background:linear-gradient(90deg,#52525b,#d4d4d8)!important;
}

/* محول */
.requests-status-card-transferred::before{
    background:linear-gradient(90deg,#9333ea,#d8b4fe)!important;
}

/* استلم المكنة */
.requests-status-card-delivered::before{
    background:linear-gradient(90deg,#059669,#6ee7b7)!important;
}

/* ملغي */
.requests-status-card-canceled::before{
    background:linear-gradient(90deg,#be185d,#f9a8d4)!important;
}
.fi-wi-stats-overview-stat-value {
    font-size:42px !important;
    font-weight:900 !important;
    color:#0f172a !important;
}

.requests-status-card-total .fi-wi-stats-overview-stat-value {
    font-size:58px !important;
    color:#ffffff !important;
}

.fi-wi-stats-overview-stat-label {
    font-size:20px !important;
    font-weight:900 !important;
    color:#475569 !important;
}

.requests-status-card-total .fi-wi-stats-overview-stat-label {
    font-size:24px !important;
    color:#dbeafe !important;
}

.fi-wi-stats-overview-stat-description {
    font-weight:900 !important;
    color:#4311A5 !important;
}

.requests-status-card-total .fi-wi-stats-overview-stat-description {
    color:#30D9EF !important;
}

.dark .requests-status-card {
    background: linear-gradient(145deg,#101827,#1C293E) !important;
    border-color: rgba(148,163,184,.18) !important;
}

.dark .fi-wi-stats-overview-stat-value {
    color:#ffffff !important;
}

.dark .fi-wi-stats-overview-stat-label {
    color:#cbd5e1 !important;
}

.dark .fi-wi-stats-overview-stat-description {
    color:#93c5fd !important;
}

@media (max-width: 1024px) {
    .requests-status-card-total {
        grid-column: auto !important;
    }
}
</style>
HTML
        );

        $total = InstallmentRequest::query()->count();

        $new = InstallmentRequest::query()->where('status', 'new')->count();
        $newRequest = InstallmentRequest::query()->where('status', 'new_request')->count();
        $pending = InstallmentRequest::query()->where('status', 'pending')->count();
        $workCheck = InstallmentRequest::query()->where('status', 'work_check')->count();
        $approved = InstallmentRequest::query()->where('status', 'approved')->count();
        $rejected = InstallmentRequest::query()->where('status', 'rejected')->count();
        $paused = InstallmentRequest::query()->where('status', 'paused')->count();
        $transferred = InstallmentRequest::query()->where('status', 'transferred')->count();
        $delivered = InstallmentRequest::query()->where('status', 'delivered')->count();
        $canceled = InstallmentRequest::query()->where('status', 'canceled')->count();

        return [
            $this->card('إجمالي الطلبات', $total, 'كل الطلبات المسجلة على السيستم', 'heroicon-o-clipboard-document-list', 'total', [2, 5, 8, 6, 12, 10, 15]),

            $this->card('انتظار', $new, 'طلبات في الانتظار', 'heroicon-o-clock', 'waiting'),
            $this->card('طلب جديد', $newRequest, 'طلبات جديدة', 'heroicon-o-sparkles', 'new-request'),
            $this->card('تحت الاستعلام', $pending, 'طلبات تحت الاستعلام', 'heroicon-o-magnifying-glass-circle', 'pending'),

            $this->card('استعلام عمل', $workCheck, 'طلبات استعلام العمل', 'heroicon-o-briefcase', 'work-check'),
            $this->card('موافقة', $approved, 'طلبات تمت الموافقة عليها', 'heroicon-o-check-circle', 'approved'),
            $this->card('رفض', $rejected, 'طلبات تم رفضها', 'heroicon-o-x-circle', 'rejected'),

            $this->card('متوقف', $paused, 'طلبات موقوفة مؤقتًا', 'heroicon-o-pause-circle', 'paused'),
            $this->card('محول', $transferred, 'طلبات تم تحويلها', 'heroicon-o-arrow-path-rounded-square', 'transferred'),
            $this->card('استلم المكنة', $delivered, 'طلبات تم تسليمها', 'heroicon-o-truck', 'delivered'),

            $this->card('الطلب ملغي', $canceled, 'طلبات تم إلغاؤها', 'heroicon-o-trash', 'canceled'),
        ];
    }

    protected function card(
        string $label,
        int $value,
        string $description,
        string $icon,
        string $type,
        ?array $chart = null
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

        return $stat;
    }
}
