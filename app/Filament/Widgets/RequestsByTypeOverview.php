<?php

namespace App\Filament\Widgets;

use Illuminate\Support\Facades\Cache;
use App\Filament\Resources\DeliveryResource;
use App\Models\InstallmentRequest;
use Carbon\CarbonInterface;
use Filament\Widgets\Widget;

/**
 * Home page: this month's main (normal) and bot requests by status - new, rejected, approved, under
 * inquiry, paused, canceled, delivered. Counts only requests filed since the 1st of the current month,
 * so the counters start again from zero every month. Admin, super admin and the owner (is_hitler)
 * only; a company (Aman) employee never sees it even with the admin flag - unless he is a super admin.
 */
class RequestsByTypeOverview extends Widget
{
    protected static string $view = 'filament.widgets.requests-by-type-overview';

    protected static ?int $sort = -3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '120s';

    /** key => label, statuses counted together, the status the link filters on, colour, icon. */
    public const ROWS = [
        'new' => ['label' => 'جديدة', 'statuses' => ['new_request', 'new'], 'filter' => 'new_request', 'color' => '#6366f1', 'icon' => 'heroicon-o-sparkles'],
        'pending' => ['label' => 'تحت الاستعلام', 'statuses' => ['pending'], 'filter' => 'pending', 'color' => '#f59e0b', 'icon' => 'heroicon-o-magnifying-glass'],
        'approved' => ['label' => 'موافقة', 'statuses' => ['approved'], 'filter' => 'approved', 'color' => '#10b981', 'icon' => 'heroicon-o-check-badge'],
        'delivered' => ['label' => 'استلمت', 'statuses' => ['delivered'], 'filter' => 'delivered', 'color' => '#06b6d4', 'icon' => 'heroicon-o-truck'],
        'paused' => ['label' => 'متوقفة', 'statuses' => ['paused'], 'filter' => 'paused', 'color' => '#94a3b8', 'icon' => 'heroicon-o-pause-circle'],
        'rejected' => ['label' => 'مرفوضة', 'statuses' => ['rejected'], 'filter' => 'rejected', 'color' => '#f43f5e', 'icon' => 'heroicon-o-x-circle'],
        'canceled' => ['label' => 'ملغية', 'statuses' => ['canceled'], 'filter' => 'canceled', 'color' => '#a855f7', 'icon' => 'heroicon-o-no-symbol'],
    ];

    public const TYPES = [
        'normal' => ['label' => 'الطلبات الأساسية', 'icon' => 'heroicon-o-clipboard-document-list', 'accent' => '#4f46e5'],
        'bot' => ['label' => 'طلبات البوت', 'icon' => 'heroicon-o-chat-bubble-left-right', 'accent' => '#0891b2'],
    ];

    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user || $user->isAmaanRestricted()) {
            return false;
        }

        return (bool) ($user->is_hitler || $user->is_super_admin || $user->is_admin);
    }

    /** [type => [status => count]] for requests filed between the two dates. */
    protected function counts(CarbonInterface $from, CarbonInterface $to): array
    {
        $key = 'dashboard:requests-by-type:'.$from->format('YmdHi').'-'.$to->format('YmdHi');

        return Cache::remember($key, now()->addMinute(), fn (): array => InstallmentRequest::query()
            ->whereIn('request_type', array_keys(self::TYPES))
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('request_type, status, COUNT(*) as total')
            ->groupBy('request_type', 'status')
            ->get()
            ->groupBy('request_type')
            ->map(fn ($rows) => $rows->pluck('total', 'status')->map(fn ($n) => (int) $n)->all())
            ->all());
    }

    protected function getViewData(): array
    {
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();
        $current = $this->counts($start, $end);

        // Same days of last month (1st .. today's date) for a fair comparison.
        $lastStart = $start->copy()->subMonthNoOverflow();
        $lastSameDay = $lastStart->copy()->addDays(min(now()->day, $lastStart->daysInMonth) - 1)->endOfDay();
        $previous = $this->counts($lastStart, $lastSameDay);

        $types = [];

        foreach (self::TYPES as $type => $meta) {
            $byStatus = $current[$type] ?? [];
            $rows = [];

            foreach (self::ROWS as $key => $row) {
                $rows[$key] = [
                    ...$row,
                    'count' => array_sum(array_map(fn ($s) => $byStatus[$s] ?? 0, $row['statuses'])),
                    'url' => DeliveryResource::getUrl('index', [
                        'activeTab' => $type,
                        'tableFilters' => [
                            'status' => ['value' => $row['filter']],
                            'created_month' => ['month' => $start->format('Y-m')],
                        ],
                    ]),
                ];
            }

            $total = array_sum($byStatus);
            $before = array_sum($previous[$type] ?? []);

            $types[$type] = [
                ...$meta,
                'rows' => $rows,
                'total' => $total,
                'delta' => $total - $before,
                'url' => DeliveryResource::getUrl('index', [
                    'activeTab' => $type,
                    'tableFilters' => ['created_month' => ['month' => $start->format('Y-m')]],
                ]),
                // Donut slices: start/end angles in percent, only for statuses with requests.
                'slices' => collect($rows)->filter(fn ($r) => $r['count'] > 0)->reduce(function ($carry, $r) use ($total) {
                    $from = $carry['at'];
                    $to = $from + ($r['count'] / max($total, 1)) * 100;
                    $carry['items'][] = "{$r['color']} {$from}% {$to}%";
                    $carry['at'] = $to;

                    return $carry;
                }, ['at' => 0, 'items' => []])['items'],
            ];

            // Statuses outside the seven cards (work check, transferred) fill the rest of the ring in grey.
            $shown = array_sum(array_column($rows, 'count'));
            if ($total > $shown) {
                $types[$type]['slices'][] = 'rgba(148,163,184,.35) '.round($shown / $total * 100, 2).'% 100%';
            }
        }

        return [
            'types' => $types,
            'month' => $start->copy()->locale('ar')->translatedFormat('F Y'),
            'dayOfMonth' => now()->day,
            'daysInMonth' => now()->daysInMonth,
            'daysLeft' => (int) now()->startOfDay()->diffInDays($end->copy()->startOfDay()),
        ];
    }
}
