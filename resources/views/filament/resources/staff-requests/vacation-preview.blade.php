{{-- Live preview of a vacation request, beside the form: the days, back to work, the year so far. --}}
@include('filament.resources.staff-requests._style')
@php
    use App\Filament\Resources\EmployeeVacationRequestResource as Vac;
    use Carbon\Carbon;

    $from = rescue(fn () => $from ? Carbon::parse($from)->locale('ar') : null, null, false);
    $to = rescue(fn () => $to ? Carbon::parse($to)->locale('ar') : null, null, false);
    $valid = $from && $to && $to->gte($from);
    $days = $valid ? Vac::days($from, $to) : 0;
    $types = [
        'normal' => ['🌴', '#0ea5e9'],
        'emergency' => ['⚡', '#f59e0b'],
        'sick' => ['🤒', '#ef4444'],
    ];
    [$icon, $c] = $types[$type] ?? ['📅', '#6366f1'];
@endphp
<div class="sx sx-vac" dir="rtl">
    <div class="sx-card">
        <div class="sx-ticket">
            <div class="sx-line" style="align-items:center">
                <span class="sx-label">كارت الإجازة</span>
                <span class="sx-chip" style="--c: {{ $c }}">{{ $icon }} {{ Vac::TYPES[$type] ?? 'اختار النوع' }}</span>
            </div>

            <div class="sx-big">{{ $days }}<small>{{ $days > 10 || $days < 3 ? 'يوم' : 'أيام' }}</small></div>

            @if ($valid)
                <div class="sx-range">
                    <div class="sx-day"><span>{{ $from->translatedFormat('l') }}</span><b>{{ $from->format('j') }}</b><span>{{ $from->translatedFormat('F') }}</span></div>
                    <div class="sx-arrow">←</div>
                    <div class="sx-day"><span>{{ $to->translatedFormat('l') }}</span><b>{{ $to->format('j') }}</b><span>{{ $to->translatedFormat('F') }}</span></div>
                </div>
            @else
                <div class="sx-tip warn">«إلى تاريخ» لازم يكون نفس يوم «من تاريخ» أو بعده.</div>
            @endif

            <div class="sx-cut"></div>

            @if ($valid)
                <div class="sx-line"><span>راجع الشغل</span><b>{{ $to->copy()->addDay()->translatedFormat('l j F') }}</b></div>
            @endif
            <div class="sx-line"><span>إجازات معتمدة السنة دي</span><b>{{ $yearDays }} يوم</b></div>
            @if ($valid)
                <div class="sx-line"><span>بعد الإجازة دي</span><b>{{ $yearDays + $days }} يوم</b></div>
            @endif
            <div class="sx-tip">✨ لما تتوافق، أيامها بتتسجل إجازة في الحضور والانصراف لوحدها.</div>
        </div>
    </div>
</div>
