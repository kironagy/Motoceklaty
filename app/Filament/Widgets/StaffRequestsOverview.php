<?php

namespace App\Filament\Widgets;

use App\Models\Staff;
use App\Models\StaffLoginLog;
use App\Models\InstallmentRequest;
use Filament\Widgets\Widget;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class StaffRequestsOverview extends Widget
{
    protected static string $view = 'filament.widgets.staff-requests-overview';

    protected static ?string $pollingInterval = '120s';

    protected int | string | array $columnSpan = 'full';

    public function getViewData(): array
    {
        $isHitler = Filament::auth()->check()
            && (int) Filament::auth()->user()->is_hitler === 1;

        // The ranking is the same for every viewer of one kind; build it once a minute.
        return Cache::remember(
            'dashboard:staff-requests-overview:'.($isHitler ? 'owner' : 'staff'),
            now()->addMinute(),
            fn (): array => $this->buildViewData(),
        );
    }

    /**
     * Owner 2026-11-19: a request counts for whoever holds it now if it reached him this month - made
     * this month, or moved to him this month. Also the requests list's filter behind each number.
     */
    public static function reachedStaffThisMonth($q): void
    {
        $start = now()->startOfMonth();
        $q->where('installment_requests.created_at', '>=', $start)
            ->orWhere('installment_requests.staff_assigned_at', '>=', $start)
            ->orWhereExists(fn ($a) => $a->selectRaw('1')->from('activity_log')
                ->where('activity_log.subject_type', InstallmentRequest::class)
                ->whereColumn('activity_log.subject_id', 'installment_requests.id')
                ->where('activity_log.created_at', '>=', $start)
                ->whereRaw("json_unquote(json_extract(activity_log.properties, '$.attributes.staff_id')) = installment_requests.staff_id"));
    }

    /** Owner 2026-11-22: each number opens the requests list on that employee and those statuses. */
    public const LINK_STATUSES = [
        'approved' => ['approved'],
        'paused' => ['paused', 'paused_aman'],
        'new' => ['new', 'new_request'],
        'pending' => ['pending', 'work_check'],
        'delivered' => ['delivered'],
        'rejected' => ['rejected'],
    ];

    private static function links(int $staffId, bool $parking): array
    {
        $url = fn (array $statuses) => \App\Filament\Resources\DeliveryResource::getUrl('index', [
            'activeTab' => 'real',
            'tableFilters' => array_filter([
                'staff_id' => ['value' => (string) $staffId],
                'status' => ['values' => $statuses],
                // a parking account counts everything it holds, any month
                'reached_staff_month' => $parking ? null : ['isActive' => true],
            ]),
        ]);
        $all = array_values(array_diff(array_keys(\App\Filament\Resources\DeliveryResource::STATUS_LABELS), ['canceled']));

        return ['total' => $url($all)] + array_map($url, self::LINK_STATUSES);
    }

    /** Per-staff counts by status (متوقف counts متوقف امان too). */
    private const COUNTS_SQL = "
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
                        WHEN status IN ('paused', 'paused_aman')
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
    ";

    protected function buildViewData(): array
    {
        /*
        |--------------------------------------------------------------------------
        | التأكد إن المستخدم هتلر
        |--------------------------------------------------------------------------
        */

       $isHitler = Filament::auth()->check()
        && (int) Filament::auth()->user()->is_hitler === 1;



        /*
        |--------------------------------------------------------------------------
        | طلبات الموظفين
        |--------------------------------------------------------------------------
        */

        $rows = InstallmentRequest::query()
            // Owner 2026-11-18: the home page shows no fake requests.
            ->where('request_type', '!=', 'fake')
            // Owner 2026-11-19: a request counts for whoever holds it now if it reached him this month -
            // made this month, or moved to him this month (an October request moved to him today counts).
            ->where(fn ($q) => self::reachedStaffThisMonth($q))
            ->selectRaw(self::COUNTS_SQL)
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

        // Owner 2026-11-19: the parking accounts (متوقف / مرفوض / موافقه ...) are not in the competition:
        // they always sit at the bottom, with everything they hold (any month).
        $parkingIds = Staff::whereIn('email', array_column(InstallmentRequest::PARKING_ACCOUNTS, 0))->pluck('id')->all();
        $parkingRows = $parkingIds === [] ? collect() : InstallmentRequest::query()
            ->where('request_type', '!=', 'fake')
            ->whereIn('staff_id', $parkingIds)
            ->selectRaw(self::COUNTS_SQL)
            ->groupBy('staff_id')
            ->get()
            ->keyBy('staff_id');

        $staff = Staff::query()
            ->get()
            ->map(function ($staff) use ($rows, $parkingRows, $parkingIds, $loginData, $isHitler) {

                $parking = in_array($staff->id, $parkingIds, true);
                $row = $parking ? $parkingRows->get($staff->id) : $rows->get($staff->id);

                $data = [
                    'id' => $staff->id,

                    'parking' => $parking,

                    'name' => $staff->name,

                    'total' => (int) ($row->total ?? 0),

                    'approved' => (int) ($row->approved_count ?? 0),

                    'paused' => (int) ($row->paused_count ?? 0),

                    'new' => (int) ($row->new_count ?? 0),

                    'pending' => (int) ($row->pending_count ?? 0),

                    'delivered' => (int) ($row->delivered_count ?? 0),

                    'rejected' => (int) ($row->rejected_count ?? 0),

                    'links' => self::links($staff->id, $parking),
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
                $item['parking'] ? 0 : 1,
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
