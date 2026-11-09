<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\DeliveryResource;
use App\Models\InstallmentRequest;
use Filament\Widgets\Widget;

/**
 * Home page: how many main (normal) and bot requests sit in each status - new, rejected, approved,
 * under inquiry, paused, canceled, delivered. Admin, super admin and the owner (is_hitler) only;
 * a company (Aman) employee never sees it even with the admin flag.
 */
class RequestsByTypeOverview extends Widget
{
    protected static string $view = 'filament.widgets.requests-by-type-overview';

    protected static ?int $sort = -3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '60s';

    /** label => statuses counted together, the status the link filters on, color. */
    public const ROWS = [
        'new' => ['label' => 'جديدة', 'statuses' => ['new_request', 'new'], 'filter' => 'new_request', 'color' => '#2563eb'],
        'rejected' => ['label' => 'مرفوضة', 'statuses' => ['rejected'], 'filter' => 'rejected', 'color' => '#dc2626'],
        'approved' => ['label' => 'موافقة', 'statuses' => ['approved'], 'filter' => 'approved', 'color' => '#16a34a'],
        'pending' => ['label' => 'تحت الاستعلام', 'statuses' => ['pending'], 'filter' => 'pending', 'color' => '#ca8a04'],
        'paused' => ['label' => 'متوقفة', 'statuses' => ['paused'], 'filter' => 'paused', 'color' => '#52525b'],
        'canceled' => ['label' => 'ملغية', 'statuses' => ['canceled'], 'filter' => 'canceled', 'color' => '#be185d'],
        'delivered' => ['label' => 'استلمت', 'statuses' => ['delivered'], 'filter' => 'delivered', 'color' => '#059669'],
    ];

    public const TYPES = ['normal' => 'الطلبات الأساسية', 'bot' => 'طلبات البوت'];

    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user || ($user->is_company_employee ?? false)) {
            return false;
        }

        return (bool) ($user->is_hitler || $user->is_super_admin || $user->is_admin);
    }

    protected function getViewData(): array
    {
        $counts = InstallmentRequest::query()
            ->whereIn('request_type', array_keys(self::TYPES))
            ->selectRaw('request_type, status, COUNT(*) as total')
            ->groupBy('request_type', 'status')
            ->get()
            ->groupBy('request_type')
            ->map(fn ($rows) => $rows->pluck('total', 'status'));

        $types = [];

        foreach (self::TYPES as $type => $label) {
            $byStatus = $counts->get($type, collect());
            $rows = [];

            foreach (self::ROWS as $key => $row) {
                $rows[$key] = [
                    ...$row,
                    'count' => (int) collect($row['statuses'])->sum(fn ($status) => (int) ($byStatus[$status] ?? 0)),
                    'url' => DeliveryResource::getUrl('index', [
                        'activeTab' => $type,
                        'tableFilters' => ['status' => ['value' => $row['filter']]],
                    ]),
                ];
            }

            $types[$type] = ['label' => $label, 'rows' => $rows, 'total' => array_sum(array_column($rows, 'count'))];
        }

        return ['types' => $types];
    }
}
