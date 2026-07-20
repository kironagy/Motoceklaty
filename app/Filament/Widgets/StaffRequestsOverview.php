<?php

namespace App\Filament\Widgets;

use App\Models\Staff;
use App\Models\InstallmentRequest;
use Filament\Widgets\Widget;

class StaffRequestsOverview extends Widget
{
    protected static string $view = 'filament.widgets.staff-requests-overview';

    protected static ?string $pollingInterval = '30s';

    protected int | string | array $columnSpan = 'full';

    public function getViewData(): array
    {
        $startOfMonth = now()->startOfMonth();
        $endOfMonth   = now()->endOfMonth();

        $rows = InstallmentRequest::query()
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->selectRaw("
                staff_id,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN status = 'paused' THEN 1 ELSE 0 END) as paused_count,
                SUM(CASE WHEN status IN ('new', 'new_request') THEN 1 ELSE 0 END) as new_count,
                SUM(CASE WHEN status IN ('pending', 'work_check') THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count
            ")
            ->whereNotNull('staff_id')
            ->groupBy('staff_id')
            ->get()
            ->keyBy('staff_id');

        $staff = Staff::query()
            ->get()
            ->map(function ($staff) use ($rows) {
                $row = $rows->get($staff->id);

                return [
                    'name' => $staff->name,
                    'total' => (int) ($row->total ?? 0),
                    'approved' => (int) ($row->approved_count ?? 0),
                    'paused' => (int) ($row->paused_count ?? 0),
                    'new' => (int) ($row->new_count ?? 0),
                    'pending' => (int) ($row->pending_count ?? 0),
                    'delivered' => (int) ($row->delivered_count ?? 0),
                    'rejected' => (int) ($row->rejected_count ?? 0),
                ];
            })
            ->sortByDesc(fn ($item) => [$item['total'], $item['approved']])
            ->values()
            ->map(function ($item, $index) {
                $item['rank'] = $index + 1;
                return $item;
            });

        return [
            'staffList' => $staff,
        ];
    }
}
