{{-- Header of an advance / vacation request (EmployeeAdvanceRequestResource, EmployeeVacationRequestResource):
     who asked, what, the decision so far and the employee's totals around it. --}}
@php
    use App\Models\EmployeeAdvanceRequest;
    use App\Models\EmployeeVacationRequest;
    use App\Filament\Resources\EmployeeVacationRequestResource as Vac;
    use Carbon\Carbon;

    $r = $getRecord();
    $staff = $r->staff;
    $statusLabel = ['pending' => 'تحت المراجعة', 'approved' => 'تمت الموافقة', 'rejected' => 'مرفوضة'][$r->status] ?? $r->status;
    $initial = mb_substr($staff?->name ?? '؟', 0, 1);

    if ($kind === 'advance') {
        $month = Carbon::parse($r->request_date ?? $r->created_at);
        $monthApproved = EmployeeAdvanceRequest::query()
            ->where('staff_id', $r->staff_id)->where('status', 'approved')
            ->whereBetween('request_date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->sum('amount');
        $salary = (float) ($staff?->monthly_salary ?? 0);
        $tiles = [
            ['قيمة السلفة', number_format((float) $r->amount).' ج', 'main'],
            ['سلف معتمدة في الشهر', number_format((float) $monthApproved).' ج', ''],
            ['المرتب الشهري', $salary ? number_format($salary).' ج' : '—', ''],
        ];
        $when = $r->request_date?->format('d/m/Y');
    } else {
        $days = Vac::days($r->from_date, $r->to_date);
        $yearDays = EmployeeVacationRequest::query()
            ->where('staff_id', $r->staff_id)->where('status', 'approved')
            ->whereYear('from_date', Carbon::parse($r->from_date)->year)
            ->get()->sum(fn ($v) => Vac::days($v->from_date, $v->to_date));
        $tiles = [
            ['عدد الأيام', $days.' '.($days > 10 || $days < 3 ? 'يوم' : 'أيام'), 'main'],
            ['النوع', Vac::TYPES[$r->vacation_type] ?? $r->vacation_type, ''],
            ['إجازات معتمدة السنة دي', $yearDays.' يوم', ''],
        ];
        $when = $r->from_date?->format('d/m/Y').' ← '.$r->to_date?->format('d/m/Y');
    }
@endphp

<style>
    .sr { --sr-card: #fff; --sr-line: #e5e7eb; --sr-soft: #f8fafc; --sr-text: #111827; --sr-muted: #6b7280;
          --sr-grad: linear-gradient(135deg, #2563eb, #7c3aed); --sr-shadow: 0 12px 30px rgba(15,23,42,.08); }
    .dark .sr { --sr-card: #1C293E; --sr-line: rgba(255,255,255,.08); --sr-soft: rgba(255,255,255,.04);
          --sr-text: #f8fafc; --sr-muted: rgba(229,231,235,.6); --sr-shadow: 0 18px 45px rgba(0,0,0,.35); }
    .sr { background: var(--sr-card); border: 1px solid var(--sr-line); border-radius: 22px; box-shadow: var(--sr-shadow); overflow: hidden; }
    .sr-top { display: flex; flex-wrap: wrap; align-items: center; gap: 1rem; padding: 1.2rem 1.3rem; background:
              radial-gradient(120% 140% at 100% 0%, rgba(37,99,235,.12), transparent 60%); }
    .sr-avatar { width: 3.3rem; height: 3.3rem; border-radius: 16px; display: grid; place-items: center; background: var(--sr-grad);
                 color: #fff; font-weight: 800; font-size: 1.3rem; flex: none; }
    .sr-who { flex: 1; min-width: 12rem; }
    .sr-name { font-size: 1.15rem; font-weight: 800; color: var(--sr-text); }
    .sr-meta { font-size: .8rem; color: var(--sr-muted); margin-top: .15rem; }
    .sr-badge { display: inline-flex; align-items: center; gap: .4rem; font-weight: 800; font-size: .85rem; padding: .45rem .9rem; border-radius: 999px; }
    .sr-badge i { width: .55rem; height: .55rem; border-radius: 50%; background: currentColor; }
    .sr-pending { color: #b45309; background: rgba(245,158,11,.14); }
    .sr-approved { color: #047857; background: rgba(16,185,129,.15); }
    .sr-rejected { color: #b91c1c; background: rgba(239,68,68,.14); }
    .dark .sr-pending { color: #fbbf24; } .dark .sr-approved { color: #34d399; } .dark .sr-rejected { color: #f87171; }
    .sr-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: .7rem; padding: 0 1.3rem 1.2rem; }
    .sr-tile { background: var(--sr-soft); border: 1px solid var(--sr-line); border-radius: 16px; padding: .8rem .9rem; }
    .sr-tile small { display: block; font-size: .75rem; color: var(--sr-muted); }
    .sr-tile b { display: block; margin-top: .2rem; font-size: 1.15rem; font-weight: 800; color: var(--sr-text); }
    .sr-tile.main { background: var(--sr-grad); border-color: transparent; }
    .sr-tile.main small { color: rgba(255,255,255,.8); } .sr-tile.main b { color: #fff; font-size: 1.35rem; }
    .sr-review { border-top: 1px dashed var(--sr-line); padding: .9rem 1.3rem; font-size: .85rem; color: var(--sr-muted); display: grid; gap: .35rem; }
    .sr-review b { color: var(--sr-text); }
    .sr-note { background: var(--sr-soft); border: 1px solid var(--sr-line); border-radius: 12px; padding: .6rem .8rem; color: var(--sr-text); white-space: pre-line; }
</style>

<div class="sr" dir="rtl">
    <div class="sr-top">
        <div class="sr-avatar">{{ $initial }}</div>
        <div class="sr-who">
            <div class="sr-name">{{ $staff?->name ?? 'موظف محذوف' }}</div>
            <div class="sr-meta">
                {{ $staff?->branch?->name ? $staff->branch->name.' · ' : '' }}{{ $when }} · اتقدم يوم {{ $r->created_at?->format('d/m/Y') }}
            </div>
        </div>
        <span class="sr-badge sr-{{ $r->status }}"><i></i>{{ $statusLabel }}</span>
    </div>

    <div class="sr-tiles">
        @foreach ($tiles as [$label, $value, $class])
            <div class="sr-tile {{ $class }}"><small>{{ $label }}</small><b>{{ $value }}</b></div>
        @endforeach
    </div>

    @if ($r->status !== 'pending' || filled($r->admin_notes))
        <div class="sr-review">
            @if ($r->reviewer)
                <div>القرار: <b>{{ $statusLabel }}</b> بواسطة <b>{{ $r->reviewer->name }}</b> · {{ $r->reviewed_at?->format('d/m/Y h:i A') }}</div>
            @endif
            @if (filled($r->admin_notes))
                <div class="sr-note">{{ $r->admin_notes }}</div>
            @endif
        </div>
    @endif
</div>
