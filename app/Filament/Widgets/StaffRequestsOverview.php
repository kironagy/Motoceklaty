<?php

namespace App\Filament\Widgets;

use App\Models\Staff;
use App\Models\StaffLoginLog;
use App\Models\InstallmentRequest;
use Filament\Widgets\Widget;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;

class StaffRequestsOverview extends Widget
{
    protected static string $view = 'filament.widgets.staff-requests-overview';

    protected static ?string $pollingInterval = '30s';

    protected int | string | array $columnSpan = 'full';

    public function getViewData(): array
    {
        /*
        |--------------------------------------------------------------------------
        | التأكد إن المستخدم هتلر
        |--------------------------------------------------------------------------
        */

       $isHitler = Filament::auth()->check()
        && (int) Filament::auth()->user()->is_hitler === 1;


        $startOfMonth = now()->startOfMonth();
        $endOfMonth   = now()->endOfMonth();

        /*
        |--------------------------------------------------------------------------
        | طلبات الموظفين
        |--------------------------------------------------------------------------
        */

        $rows = InstallmentRequest::query()
            ->whereBetween('created_at', [
                $startOfMonth,
                $endOfMonth,
            ])
            ->selectRaw("
                staff_id,

                SUM(
                    CASE
                        WHEN status != 'canceled'
                        THEN 1
                        ELSE 0
                    END
                ) as total,

                SUM(
                    CASE
                        WHEN status = 'approved'
                        THEN 1
                        ELSE 0
                    END
                ) as approved_count,

                SUM(
                    CASE
                        WHEN status = 'paused'
                        THEN 1
                        ELSE 0
                    END
                ) as paused_count,

                SUM(
                    CASE
                        WHEN status IN ('new', 'new_request')
                        THEN 1
                        ELSE 0
                    END
                ) as new_count,

                SUM(
                    CASE
                        WHEN status IN ('pending', 'work_check')
                        THEN 1
                        ELSE 0
                    END
                ) as pending_count,

                SUM(
                    CASE
                        WHEN status = 'delivered'
                        THEN 1
                        ELSE 0
                    END
                ) as delivered_count,

                SUM(
                    CASE
                        WHEN status = 'rejected'
                        THEN 1
                        ELSE 0
                    END
                ) as rejected_count
            ")
            ->whereNotNull('staff_id')
            ->groupBy('staff_id')
            ->get()
            ->keyBy('staff_id');

        /*
        |--------------------------------------------------------------------------
        | بيانات الدخول - هتلر فقط
        |--------------------------------------------------------------------------
        */

        $loginData = collect();

        if ($isHitler) {
            $todayStart = now()->startOfDay();
            $todayEnd   = now()->endOfDay();

            $loginData = StaffLoginLog::query()
                ->whereBetween('logged_in_at', [
                    $todayStart,
                    $todayEnd,
                ])
                ->whereNotNull('staff_id')
                ->orderByDesc('logged_in_at')
                ->get()
                ->groupBy('staff_id');
        }

        /*
        |--------------------------------------------------------------------------
        | الموظفين
        |--------------------------------------------------------------------------
        */

        $staff = Staff::query()
            ->get()
            ->map(function ($staff) use ($rows, $loginData, $isHitler) {

                $row = $rows->get($staff->id);

                $data = [
                    'id' => $staff->id,

                    'name' => $staff->name,

                    'total' => (int) ($row->total ?? 0),

                    'approved' => (int) ($row->approved_count ?? 0),

                    'paused' => (int) ($row->paused_count ?? 0),

                    'new' => (int) ($row->new_count ?? 0),

                    'pending' => (int) ($row->pending_count ?? 0),

                    'delivered' => (int) ($row->delivered_count ?? 0),

                    'rejected' => (int) ($row->rejected_count ?? 0),
                ];

                /*
                |--------------------------------------------------------------------------
                | Login Information
                |--------------------------------------------------------------------------
                */

                if ($isHitler) {

                    $logs = $loginData->get(
                        $staff->id,
                        collect()
                    );

                    $devices = $logs
                        ->pluck('device_hash')
                        ->filter()
                        ->unique()
                        ->count();

                    $data['login'] = [
                        'today_count' => $logs->count(),

                        'devices_count' => $devices,

                        'last_login' => optional(
                            $logs->first()
                        )->logged_in_at?->format('Y-m-d H:i:s'),

                        'last_ip' => optional(
                            $logs->first()
                        )->ip_address,

                        'logs' => $logs
                            ->map(function ($log) use ($logs) {

                                return [
                                    'time' => optional(
                                        $log->logged_in_at
                                    )->format('h:i A'),

                                    'date' => optional(
                                        $log->logged_in_at
                                    )->format('Y-m-d'),

                                    'ip' => $log->ip_address,

                                    'browser' => $log->browser,

                                    'platform' => $log->platform,

                                    'device_type' => $log->device_type,

                                    'device_hash' => $log->device_hash,

                                    'same_device' =>
                                        $logs->first()?->device_hash ===
                                        $log->device_hash,
                                ];
                            })
                            ->values()
                            ->toArray(),
                    ];
                }

                return $data;
            })
            ->sortByDesc(fn ($item) => [
                $item['total'],
                $item['approved'],
            ])
            ->values()
            ->map(function ($item, $index) {

                $item['rank'] = $index + 1;

                return $item;
            });

        return [
            'staffList' => $staff,

            'isHitler' => $isHitler,
        ];
    }
}
