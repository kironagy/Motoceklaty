{{-- Owner 2026-11-18: why the bot is late or stopped (App\Domain\Bots\BotDelays). Refreshes every 30 s. --}}
@php
    $dur = function (?int $s) {
        if ($s === null) return '—';
        if ($s < 60) return $s.' ث';
        if ($s < 3600) return intdiv($s, 60).' د';
        return intdiv($s, 3600).' س '.(intdiv($s % 3600, 60) ? intdiv($s % 3600, 60).' د' : '');
    };
    $ago = fn ($t) => $t ? $dur((int) $t->diffInSeconds(now())) : '—';
    $levelIcon = ['stop' => 'heroicon-o-no-symbol', 'warn' => 'heroicon-o-exclamation-triangle', 'ok' => 'heroicon-o-check-circle', 'info' => 'heroicon-o-information-circle'];
    $worst = collect($blockers)->first();
@endphp

<style>
    .bd { display: grid; gap: 18px; }
    .bd-head { padding: 18px 20px; display: flex; gap: 14px; align-items: center; }
    .bd-head .ico { flex: none; width: 52px; height: 52px; border-radius: 18px; display: grid; place-items: center; }
    .bd-head .ico svg { width: 28px; height: 28px; }
    .bd-head h3 { margin: 0; font-size: 20px; font-weight: 900; color: var(--ink); }
    .bd-head p { margin: 4px 0 0; font-size: 13px; font-weight: 600; color: var(--muted); }
    .bd-stop { --c: #ef4444; } .bd-warn { --c: #f59e0b; } .bd-ok { --c: #10b981; } .bd-info { --c: #6366f1; }
    .bd-head .ico, .bd-item .ico { color: var(--c); background: color-mix(in srgb, var(--c) 14%, transparent); }
    .bd-list { display: grid; gap: 10px; padding: 0 20px 20px; }
    .bd-item { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border-radius: 16px; background: var(--soft); border: 1px solid var(--line); border-inline-start: 4px solid var(--c); }
    .bd-item .ico { flex: none; width: 34px; height: 34px; border-radius: 11px; display: grid; place-items: center; }
    .bd-item .ico svg { width: 19px; height: 19px; }
    .bd-item b { display: block; font-size: 14px; font-weight: 900; color: var(--ink); }
    .bd-item span { display: block; margin-top: 2px; font-size: 12.5px; font-weight: 600; color: var(--muted); line-height: 1.6; }
    .bd-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
    @media (max-width: 1100px) { .bd-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 560px) { .bd-grid { grid-template-columns: 1fr; } }
    .bd-sec { padding: 18px 20px; }
    .bd-sec h4 { margin: 0 0 12px; font-size: 16px; font-weight: 900; color: var(--ink); display: flex; align-items: center; gap: 8px; }
    .bd-sec h4 svg { width: 20px; height: 20px; color: var(--muted); }
    .bd-two { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    @media (max-width: 900px) { .bd-two { grid-template-columns: 1fr; } }
    .bd-wait { padding: 14px; border-radius: 16px; background: var(--soft); border: 1px solid var(--line); }
    .bd-wait .t { font-size: 13px; font-weight: 800; color: var(--muted); }
    .bd-wait .n { margin-top: 6px; font-size: 26px; font-weight: 900; color: var(--ink); font-variant-numeric: tabular-nums; }
    .bd-wait .n small { font-size: 13px; color: var(--muted); }
    .bd-wait .row { display: flex; justify-content: space-between; margin-top: 8px; font-size: 12.5px; font-weight: 700; color: var(--muted); }
    .bd-wait .row b { color: var(--ink); }
    .bd-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .bd-table th { text-align: start; padding: 8px 10px; font-size: 12px; font-weight: 800; color: var(--muted); border-bottom: 1px solid var(--line); }
    .bd-table td { padding: 9px 10px; border-bottom: 1px solid var(--line); color: var(--ink); font-weight: 600; vertical-align: top; }
    .bd-table tr:last-child td { border-bottom: 0; }
    .bd-wrap { overflow-x: auto; }
    .bd-tag { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11.5px; font-weight: 800; }
    .bd-tag.new { color: #4338ca; background: rgba(99, 102, 241, .12); } .dark .bd-tag.new { color: #a5b4fc; }
    .bd-tag.old { color: #92400e; background: rgba(245, 158, 11, .14); } .dark .bd-tag.old { color: #fcd34d; }
    .bd-tag.done { color: #047857; background: rgba(16, 185, 129, .12); } .dark .bd-tag.done { color: #6ee7b7; }
    .bd-tag.failed { color: #b91c1c; background: rgba(239, 68, 68, .12); } .dark .bd-tag.failed { color: #fca5a5; }
    .bd-tag.busy { color: #1d4ed8; background: rgba(59, 130, 246, .12); } .dark .bd-tag.busy { color: #93c5fd; }
    .bd-muted { color: var(--muted); font-size: 13px; font-weight: 600; }
    .bd-slow { color: #dc2626 !important; font-weight: 800 !important; }
</style>

<div class="bd">
    {{-- الحالة دلوقتي --}}
    <div class="ba-card bd-{{ $worst['level'] ?? 'ok' }}">
        <div class="bd-head">
            <span class="ico"><x-filament::icon :icon="$levelIcon[$worst['level'] ?? 'ok']" /></span>
            <div>
                <h3>{{ $worst['title'] ?? 'شغال' }}</h3>
                <p>آخر تحديث {{ now()->timezone('Africa/Cairo')->format('h:i:s a') }} — بتتحدث لوحدها كل 30 ثانية</p>
            </div>
        </div>
        <div class="bd-list">
            @foreach ($blockers as $b)
                <div class="bd-item bd-{{ $b['level'] }}">
                    <span class="ico"><x-filament::icon :icon="$levelIcon[$b['level']]" /></span>
                    <div><b>{{ $b['title'] }}</b>@if ($b['detail'])<span>{{ $b['detail'] }}</span>@endif</div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- الطابور --}}
    <div class="bd-grid">
        <div class="ba-card ba-kpi" style="--c: #6366f1">
            <div class="ba-kpi-top"><span class="ba-kpi-label">عملاء جداد مستنيين</span><span class="ba-kpi-ico"><x-filament::icon icon="heroicon-o-user-plus" /></span></div>
            <div class="ba-kpi-value">{{ $queue['new'] }}</div>
            <div class="ba-kpi-sub">{{ $queue['new_since'] ? 'أقدم واحد مستني من '.$ago($queue['new_since']) : 'مافيش حد مستني' }}</div>
        </div>
        <div class="ba-card ba-kpi" style="--c: #f59e0b">
            <div class="ba-kpi-top"><span class="ba-kpi-label">عملاء قدام هيرد عليهم</span><span class="ba-kpi-ico"><x-filament::icon icon="heroicon-o-archive-box" /></span></div>
            <div class="ba-kpi-value">{{ $queue['old'] }}</div>
            <div class="ba-kpi-sub">{{ $queue['old_since'] ? 'أقدمهم من '.$ago($queue['old_since']).' — بيترد عليهم لما الجداد يخلصوا' : 'مافيش' }}</div>
        </div>
        <div class="ba-card ba-kpi" style="--c: #10b981">
            <div class="ba-kpi-top"><span class="ba-kpi-label">سرعته دلوقتي</span><span class="ba-kpi-ico"><x-filament::icon icon="heroicon-o-bolt" /></span></div>
            <div class="ba-kpi-value">{{ $queue['per_hour'] ?? '—' }} <small>عميل/ساعة</small></div>
            <div class="ba-kpi-sub">{{ $queue['per_turn_seconds'] ? 'عميل كل '.$dur($queue['per_turn_seconds']).' (آخر ساعتين)' : 'مفيش ردود كفاية في آخر ساعتين' }}</div>
        </div>
        <div class="ba-card ba-kpi" style="--c: #0ea5e9">
            <div class="ba-kpi-top"><span class="ba-kpi-label">هيخلّص الطابور في</span><span class="ba-kpi-ico"><x-filament::icon icon="heroicon-o-clock" /></span></div>
            <div class="ba-kpi-value" style="font-size:26px">{{ $queue['new_done_in'] ? $dur($queue['new_done_in']) : '—' }}</div>
            <div class="ba-kpi-sub">للجداد{{ $queue['all_done_in'] ? ' — والكل (جداد + قدام) في '.$dur($queue['all_done_in']) : '' }} — تقدير بالسرعة الحالية</div>
        </div>
    </div>

    {{-- النهارده --}}
    <div class="ba-card bd-sec">
        <h4><x-filament::icon icon="heroicon-o-calendar-days" /> النهارده: العميل استنى قد إيه لحد ما اترد عليه</h4>
        <div class="bd-two">
            @foreach (['new' => 'عملاء جداد', 'old' => 'عملاء قدام (رد تاني)'] as $k => $label)
                @php $w = $todayWaits[$k]; @endphp
                <div class="bd-wait">
                    <div class="t">{{ $label }}</div>
                    <div class="n">{{ $w['count'] }} <small>اترد عليهم</small></div>
                    <div class="row"><span>نص العملاء استنوا أقل من</span><b @class(['bd-slow' => ($w['median'] ?? 0) > 900])>{{ $dur($w['median']) }}</b></div>
                    <div class="row"><span>9 من كل 10 استنوا أقل من</span><b @class(['bd-slow' => ($w['p90'] ?? 0) > 1800])>{{ $dur($w['p90']) }}</b></div>
                    <div class="row"><span>أطول انتظار</span><b>{{ $dur($w['max']) }}</b></div>
                </div>
            @endforeach
        </div>
        @if ($todayWaits['failed'])
            <p class="bd-muted" style="margin-top:10px">{{ $todayWaits['failed'] }} رد فشل النهارده — الأسباب تحت.</p>
        @endif
    </div>

    {{-- المشاكل --}}
    <div class="ba-card bd-sec">
        <h4><x-filament::icon icon="heroicon-o-exclamation-triangle" /> اللي عطّل الإرسال في آخر 24 ساعة</h4>
        @if ($problems->isEmpty())
            <div class="bd-muted">مافيش مشاكل في آخر 24 ساعة.</div>
        @else
            <div class="bd-wrap"><table class="bd-table">
                <thead><tr><th>السبب</th><th>يعني إيه</th><th>عدد المرات</th><th>آخر مرة</th></tr></thead>
                <tbody>
                    @foreach ($problems as $p)
                        <tr><td><b>{{ $p['title'] }}</b></td><td class="bd-muted">{{ $p['hint'] }}</td><td>{{ $p['count'] }}</td><td>من {{ $ago($p['last']) }}</td></tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </div>

    {{-- آخر الردود --}}
    <div class="ba-card bd-sec">
        <h4><x-filament::icon icon="heroicon-o-queue-list" /> آخر الردود</h4>
        @if ($recent->isEmpty())
            <div class="bd-muted">مافيش ردود في آخر 24 ساعة.</div>
        @else
            <div class="bd-wrap"><table class="bd-table">
                <thead><tr><th>الوقت</th><th>العميل</th><th>النوع</th><th>بعت من</th><th>استنى</th><th>النتيجة</th></tr></thead>
                <tbody>
                    @foreach ($recent as $r)
                        <tr>
                            <td>{{ $r['at']->timezone('Africa/Cairo')->format('h:i a') }}</td>
                            <td dir="ltr" style="text-align:start">{{ $r['phone'] ?? '—' }}</td>
                            <td><span class="bd-tag {{ $r['old'] ? 'old' : 'new' }}">{{ $r['old'] ? 'قديم' : 'جديد' }}</span></td>
                            <td>{{ $r['since']->timezone('Africa/Cairo')->format('h:i a d/m') }}</td>
                            <td @class(['bd-slow' => $r['waited'] > 1800])>{{ $dur($r['waited']) }}</td>
                            <td>
                                @if ($r['status'] === 'done') <span class="bd-tag done">اترد</span>
                                @elseif (in_array($r['status'], ['processing', 'generated'], true)) <span class="bd-tag busy">{{ $r['why'] ? 'بيتعاد' : 'شغال عليه' }}</span>
                                @elseif ($r['status'] === 'skipped') <span class="bd-tag old">اتساب</span>
                                @else <span class="bd-tag failed">فشل</span>
                                @endif
                                @if ($r['why'])<div class="bd-muted" style="font-size:12px;margin-top:3px">{{ $r['why'] }}</div>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
</div>
