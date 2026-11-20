<?php

namespace App\Filament\Resources\AmanExtensionControlResource\Widgets;

use App\Filament\Resources\AmanUploadResource;
use App\Filament\Resources\DeliveryResource;
use App\Models\AmanUpload;
use App\Models\Staff;
use Carbon\Carbon;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-11-20: the extension control's daily report - per Aman employee, per day: how many he
 * uploaded, how many requests he worked on and their names, how each ended, and his inquiry follow-ups.
 */
class AmanDailyReport extends Widget
{
    protected static string $view = 'filament.aman-control.daily-report';

    protected int|string|array $columnSpan = 'full';

    public string $day = '';

    public ?int $open = null;

    public static function canView(): bool
    {
        return (bool) (Auth::user()?->is_hitler ?? false);
    }

    public function mount(): void
    {
        $this->day = today()->toDateString();
    }

    public function shiftDay(int $by): void
    {
        $d = $this->date()->addDays($by);
        $this->day = ($d->isFuture() ? today() : $d)->toDateString();
        $this->open = null;
    }

    public function toggle(int $staffId): void
    {
        $this->open = $this->open === $staffId ? null : $staffId;
    }

    private function date(): Carbon
    {
        try {
            return Carbon::parse($this->day ?: 'today')->startOfDay();
        } catch (\Throwable) {
            return today();
        }
    }

    public const RESULTS = [
        'uploaded' => ['اترفع', 'good'],
        'rejected_before' => ['مرفوض قبل كده', 'bad'],
        'held' => ['اتوقف (متوقف امان)', 'warn'],
        'canceled' => ['اتلغى', 'violet'],
        'error' => ['مشكلة', 'warn'],
        'released' => ['سابه من غير رفع', 'muted'],
        'claimed' => ['شغال عليه', 'info'],
    ];

    protected function getViewData(): array
    {
        $from = $this->date();
        $to = $from->copy()->endOfDay();

        $uploads = AmanUpload::query()
            ->with('request:id,applicant_name,applicant_national_id,installment_type,status')
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get();

        $checks = DB::table('aman_inquiry_checks as c')
            ->leftJoin('installment_requests as r', 'r.id', '=', 'c.installment_request_id')
            ->whereBetween('c.created_at', [$from, $to])
            ->orderBy('c.created_at')
            ->get(['c.*', 'r.applicant_name']);

        // last 7 days of uploads per employee, for the small bars
        $weekFrom = $from->copy()->subDays(6);
        $week = AmanUpload::query()
            ->where('status', 'uploaded')
            ->whereBetween('created_at', [$weekFrom, $to])
            ->selectRaw('staff_id, DATE(created_at) d, count(*) n')
            ->groupBy('staff_id', 'd')
            ->get()
            ->groupBy('staff_id');

        $ids = $uploads->pluck('staff_id')->merge($checks->pluck('staff_id'))->unique()->filter();
        $staff = Staff::whereIn('id', $ids)->get(['id', 'name', 'email', 'aman_extension_enabled'])->keyBy('id');

        $people = $ids->map(function ($id) use ($uploads, $checks, $staff, $week, $weekFrom) {
            $mine = $uploads->where('staff_id', $id)->values();
            $myChecks = $checks->where('staff_id', $id)->values();
            $counts = collect(array_keys(self::RESULTS))->mapWithKeys(fn ($k) => [$k => $mine->where('status', $k)->count()]);
            $done = $mine->whereIn('status', ['uploaded', 'rejected_before', 'held', 'canceled']);
            $durations = $mine->where('status', 'uploaded')
                ->filter(fn ($u) => $u->claimed_at && $u->finished_at)
                ->map(fn ($u) => $u->claimed_at->diffInSeconds($u->finished_at));
            $times = $mine->pluck('created_at')->merge($myChecks->pluck('created_at')->map(fn ($t) => Carbon::parse($t)));
            $days = collect(range(0, 6))->map(function ($i) use ($weekFrom, $week, $id) {
                $d = $weekFrom->copy()->addDays($i);

                return ['label' => $d->locale('ar')->isoFormat('dd'), 'n' => (int) optional($week->get($id)?->firstWhere('d', $d->toDateString()))->n];
            });

            return [
                'id' => $id,
                'name' => $staff[$id]->name ?? ('#'.$id),
                'email' => $staff[$id]->email ?? '',
                'enabled' => (bool) ($staff[$id]->aman_extension_enabled ?? false),
                'worked' => $mine->pluck('installment_request_id')->unique()->count(),
                'counts' => $counts,
                'rate' => $mine->count() ? (int) round($counts['uploaded'] * 100 / $mine->count()) : 0,
                'finished' => $done->count(),
                'avg' => $durations->count() ? (int) round($durations->avg() / 60) : null,
                'first' => $times->min(),
                'last' => $times->max(),
                'checks' => $myChecks->count(),
                'checksChanged' => $myChecks->where('changed', 1)->count(),
                'days' => $days,
                'dayMax' => max(1, $days->max('n')),
                'rows' => $mine->map(fn (AmanUpload $u) => [
                    'at' => $u->created_at,
                    'name' => $u->request?->applicant_name ?? '—',
                    'request_id' => $u->installment_request_id,
                    'url' => $u->installment_request_id ? DeliveryResource::getUrl('edit', ['record' => $u->installment_request_id]) : null,
                    'status' => $u->status,
                    'ref' => $u->aman_ref,
                    'message' => $u->message,
                    'minutes' => $u->claimed_at && $u->finished_at ? max(1, (int) round($u->claimed_at->diffInSeconds($u->finished_at) / 60)) : null,
                ]),
                'checkRows' => $myChecks->map(fn ($c) => [
                    'at' => Carbon::parse($c->created_at),
                    'name' => $c->applicant_name ?? '—',
                    'url' => DeliveryResource::getUrl('edit', ['record' => $c->installment_request_id]),
                    'text' => $c->text,
                    'status' => $c->status,
                    'changed' => (bool) $c->changed,
                ]),
            ];
        })->sortByDesc(fn ($p) => [$p['counts']['uploaded'], $p['worked'], $p['checks']])->values();

        return [
            'date' => $from,
            'isToday' => $from->isToday(),
            'people' => $people,
            'totals' => [
                'uploaded' => $uploads->where('status', 'uploaded')->count(),
                'worked' => $uploads->pluck('installment_request_id')->unique()->count(),
                'rejected' => $uploads->where('status', 'rejected_before')->count(),
                'held' => $uploads->whereIn('status', ['held', 'canceled'])->count(),
                'problems' => $uploads->whereIn('status', ['error', 'released'])->count(),
                'checks' => $checks->count(),
                'changed' => $checks->where('changed', 1)->count(),
                'staff' => $people->count(),
            ],
            'results' => self::RESULTS,
            'statusLabels' => DeliveryResource::STATUS_LABELS,
            'uploadsUrl' => AmanUploadResource::getUrl('index'),
        ];
    }
}
