<x-filament-widgets::widget>
<style>
    .adr { direction: rtl; --o:#ff6100; --o2:#ff8a3d; --ink:#16130f; --mut:#7a7268; --line:rgba(148,163,184,.22); --card:#fff; --soft:#fff7f1; }
    .dark .adr { --ink:#f5f1ec; --mut:#a8a29e; --line:rgba(255,255,255,.08); --card:#1c1917; --soft:#292524; }
    .adr-hero { position:relative; overflow:hidden; border-radius:28px; padding:26px 28px; color:#fff;
        background: radial-gradient(120% 140% at 0% 0%, #ffb27a 0%, rgba(255,178,122,0) 45%), linear-gradient(135deg, #ff6100 0%, #e04f00 55%, #8a2c00 100%);
        box-shadow: 0 22px 50px rgba(224,79,0,.28); }
    .adr-hero::after { content:''; position:absolute; inset:auto -60px -90px auto; width:260px; height:260px; border-radius:50%; background:rgba(255,255,255,.08); }
    .adr-hero::before { content:''; position:absolute; inset:-80px auto auto -40px; width:200px; height:200px; border-radius:50%; background:rgba(255,255,255,.06); }
    .adr-top { display:flex; flex-wrap:wrap; gap:16px; align-items:center; justify-content:space-between; position:relative; z-index:1; }
    .adr-top h2 { margin:0; font-size:26px; font-weight:900; letter-spacing:-.3px; }
    .adr-top p { margin:4px 0 0; opacity:.85; font-size:14px; }
    .adr-nav { display:flex; align-items:center; gap:8px; background:rgba(255,255,255,.14); padding:6px; border-radius:16px; backdrop-filter: blur(6px); }
    .adr-nav button { border:0; background:rgba(255,255,255,.18); color:#fff; width:36px; height:36px; border-radius:11px; font-size:18px; font-weight:900; cursor:pointer; }
    .adr-nav button:hover:not(:disabled) { background:rgba(255,255,255,.3); }
    .adr-nav button:disabled { opacity:.35; cursor:default; }
    .adr-nav input { border:0; border-radius:11px; padding:7px 10px; font-weight:800; color:#8a2c00; background:#fff; }
    .adr-nav .today { width:auto; padding:0 12px; font-size:13px; }
    .adr-kpis { position:relative; z-index:1; display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-top:22px; }
    .adr-kpi { background:rgba(255,255,255,.14); border:1px solid rgba(255,255,255,.18); border-radius:20px; padding:14px 16px; }
    .adr-kpi b { display:block; font-size:30px; font-weight:900; line-height:1.1; }
    .adr-kpi span { font-size:13px; opacity:.9; font-weight:700; }
    .adr-kpi small { display:block; font-size:12px; opacity:.75; margin-top:2px; }

    .adr-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:18px; margin-top:20px; }
    .adr-card { position:relative; background:var(--card); border:1px solid var(--line); border-radius:24px; padding:20px; box-shadow:0 14px 36px rgba(15,23,42,.07); color:var(--ink); overflow:hidden; }
    .adr-card.first { border-color:rgba(255,97,0,.45); box-shadow:0 18px 44px rgba(255,97,0,.16); }
    .adr-card.first::before { content:''; position:absolute; inset:0 0 auto 0; height:4px; background:linear-gradient(90deg,var(--o),var(--o2),#ffd36e); }
    .adr-head { display:flex; align-items:center; gap:12px; }
    .adr-ava { width:48px; height:48px; border-radius:16px; display:grid; place-items:center; font-weight:900; font-size:18px; color:#fff; background:linear-gradient(135deg,var(--o),var(--o2)); flex-shrink:0; }
    .adr-who { flex:1; min-width:0; }
    .adr-who b { display:block; font-size:16.5px; font-weight:900; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .adr-who span { font-size:12px; color:var(--mut); }
    .adr-medal { font-size:26px; }
    .adr-lock { font-size:11px; font-weight:800; padding:3px 9px; border-radius:999px; background:#fee2e2; color:#b91c1c; }

    .adr-main { display:flex; align-items:center; gap:18px; margin-top:16px; }
    .adr-ring { --p:0; width:92px; height:92px; border-radius:50%; flex-shrink:0; display:grid; place-items:center;
        background: conic-gradient(var(--o) calc(var(--p)*1%), var(--soft) 0); }
    .adr-ring > div { width:72px; height:72px; border-radius:50%; background:var(--card); display:grid; place-items:center; text-align:center; line-height:1.05; }
    .adr-ring b { font-size:26px; font-weight:900; color:var(--o); }
    .adr-ring span { font-size:10.5px; color:var(--mut); font-weight:700; }
    .adr-stats { flex:1; display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:8px; }
    .adr-stat { background:var(--soft); border-radius:14px; padding:8px 10px; }
    .adr-stat b { display:block; font-size:18px; font-weight:900; }
    .adr-stat span { font-size:11.5px; color:var(--mut); font-weight:700; }

    .adr-chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:14px; }
    .adr-chip { font-size:12px; font-weight:800; padding:4px 10px; border-radius:999px; }
    .c-good { background:#dcfce7; color:#15803d; } .c-bad { background:#fee2e2; color:#b91c1c; } .c-warn { background:#fef3c7; color:#92400e; }
    .c-violet { background:#f3e8ff; color:#7e22ce; } .c-muted { background:#f1f5f9; color:#475569; } .c-info { background:#e0f2fe; color:#0369a1; }
    .dark .c-good { background:rgba(34,197,94,.15); color:#86efac; } .dark .c-bad { background:rgba(239,68,68,.15); color:#fca5a5; }
    .dark .c-warn { background:rgba(245,158,11,.15); color:#fcd34d; } .dark .c-violet { background:rgba(168,85,247,.15); color:#d8b4fe; }
    .dark .c-muted { background:rgba(148,163,184,.15); color:#cbd5e1; } .dark .c-info { background:rgba(14,165,233,.15); color:#7dd3fc; }

    .adr-week { display:flex; align-items:flex-end; gap:6px; height:58px; margin-top:16px; padding-top:4px; }
    .adr-week div { flex:1; display:flex; flex-direction:column; align-items:center; gap:3px; height:100%; justify-content:flex-end; }
    .adr-week i { display:block; width:100%; border-radius:7px 7px 3px 3px; background:linear-gradient(180deg,var(--o2),var(--o)); min-height:3px; opacity:.85; }
    .adr-week div.now i { opacity:1; box-shadow:0 4px 12px rgba(255,97,0,.35); }
    .adr-week em { font-style:normal; font-size:10px; color:var(--mut); font-weight:700; }
    .adr-week strong { font-size:10px; color:var(--ink); font-weight:900; }
    .adr-foot { display:flex; flex-wrap:wrap; gap:14px; align-items:center; margin-top:14px; font-size:12.5px; color:var(--mut); font-weight:700; }
    .adr-foot b { color:var(--ink); }
    .adr-more { margin-inline-start:auto; border:0; cursor:pointer; font-weight:900; font-size:13px; color:#fff; padding:8px 14px; border-radius:12px; background:linear-gradient(135deg,var(--o),var(--o2)); box-shadow:0 6px 16px rgba(255,97,0,.28); }

    .adr-list { margin-top:16px; border-top:1px dashed var(--line); padding-top:12px; }
    .adr-list h4 { margin:10px 0 8px; font-size:13.5px; font-weight:900; }
    .adr-row { display:grid; grid-template-columns:56px 1fr auto; gap:10px; align-items:start; padding:9px 0; border-bottom:1px solid var(--line); }
    .adr-row:last-child { border-bottom:0; }
    .adr-time { font-size:12px; font-weight:900; color:var(--o); direction:ltr; text-align:right; }
    .adr-name a { font-weight:800; color:var(--ink); text-decoration:none; }
    .adr-name a:hover { color:var(--o); }
    .adr-name small { display:block; color:var(--mut); font-size:11.5px; margin-top:2px; }
    .adr-empty { margin-top:20px; background:var(--card); border:1px dashed var(--line); border-radius:24px; padding:40px; text-align:center; color:var(--mut); font-weight:800; }
    .adr-empty div { font-size:42px; }
    @media (max-width: 1100px) { .adr-grid { grid-template-columns:1fr; } }
    @media (max-width: 700px) { .adr-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } .adr-stats { grid-template-columns:repeat(2,minmax(0,1fr)); } .adr-main { flex-direction:column; align-items:stretch; } .adr-ring { align-self:center; } }
</style>

<div class="adr">
    <div class="adr-hero">
        <div class="adr-top">
            <div>
                <h2>تقرير الإكستنشن اليومي</h2>
                <p>{{ $date->locale('ar')->isoFormat('dddd D MMMM YYYY') }} — مين رفع قد إيه، واشتغل على مين، وكل طلب خلص على إيه</p>
            </div>
            <div class="adr-nav">
                <button type="button" wire:click="shiftDay(-1)" title="اليوم اللي قبله">›</button>
                <input type="date" wire:model.live="day" max="{{ today()->toDateString() }}">
                <button type="button" wire:click="shiftDay(1)" title="اليوم اللي بعده" @disabled($isToday)>‹</button>
                @unless ($isToday)
                    <button type="button" class="today" wire:click="$set('day', '{{ today()->toDateString() }}')">النهارده</button>
                @endunless
            </div>
        </div>
        <div class="adr-kpis">
            <div class="adr-kpi"><b>{{ number_format($totals['uploaded']) }}</b><span>طلب اترفع على أمان</span><small>من {{ number_format($totals['worked']) }} طلب اتشغل عليه</small></div>
            <div class="adr-kpi"><b>{{ number_format($totals['rejected']) }}</b><span>مرفوض قبل كده</span><small>{{ number_format($totals['held']) }} اتوقف أو اتلغى</small></div>
            <div class="adr-kpi"><b>{{ number_format($totals['checks']) }}</b><span>متابعة استعلام</span><small>{{ number_format($totals['changed']) }} طلب حالته اتغيرت</small></div>
            <div class="adr-kpi"><b>{{ number_format($totals['staff']) }}</b><span>موظف اشتغل</span><small>{{ number_format($totals['problems']) }} مشكلة أو اتساب</small></div>
        </div>
    </div>

    @if ($people->isEmpty())
        <div class="adr-empty"><div>😴</div>محدش اشتغل على الإكستنشن في اليوم ده.</div>
    @else
        <div class="adr-grid">
            @foreach ($people as $i => $p)
                @php
                    $words = preg_split('/\s+/u', trim($p['name']));
                    $initials = mb_substr($words[0] ?? '', 0, 1) . mb_substr($words[1] ?? '', 0, 1);
                    $medal = [0 => '🥇', 1 => '🥈', 2 => '🥉'][$i] ?? null;
                @endphp
                <div class="adr-card {{ $i === 0 && $p['counts']['uploaded'] > 0 ? 'first' : '' }}" wire:key="adr-{{ $p['id'] }}">
                    <div class="adr-head">
                        <div class="adr-ava">{{ $initials }}</div>
                        <div class="adr-who"><b>{{ $p['name'] }}</b><span>{{ $p['email'] }}</span></div>
                        @unless ($p['enabled'])<span class="adr-lock">🔒 مقفولة</span>@endunless
                        @if ($medal && $p['counts']['uploaded'] > 0)<span class="adr-medal">{{ $medal }}</span>@endif
                    </div>

                    <div class="adr-main">
                        <div class="adr-ring" style="--p: {{ $p['rate'] }}">
                            <div><div><b>{{ $p['counts']['uploaded'] }}</b><br><span>اترفع</span></div></div>
                        </div>
                        <div class="adr-stats">
                            <div class="adr-stat"><b>{{ $p['worked'] }}</b><span>اشتغل على</span></div>
                            <div class="adr-stat"><b>{{ $p['rate'] }}%</b><span>نسبة الرفع</span></div>
                            <div class="adr-stat"><b>{{ $p['avg'] !== null ? $p['avg'] . ' د' : '—' }}</b><span>متوسط الطلب</span></div>
                            <div class="adr-stat"><b>{{ $p['checks'] }}</b><span>متابعة استعلام</span></div>
                            <div class="adr-stat"><b>{{ $p['checksChanged'] }}</b><span>حالة اتغيرت</span></div>
                            <div class="adr-stat"><b>{{ $p['finished'] }}</b><span>خلّصه</span></div>
                        </div>
                    </div>

                    <div class="adr-chips">
                        @foreach ($results as $key => [$label, $tone])
                            @if ($p['counts'][$key] > 0)
                                <span class="adr-chip c-{{ $tone }}">{{ $label }}: {{ $p['counts'][$key] }}</span>
                            @endif
                        @endforeach
                    </div>

                    <div class="adr-week" title="الرفع في آخر ٧ أيام">
                        @foreach ($p['days'] as $k => $d)
                            <div class="{{ $loop->last ? 'now' : '' }}">
                                @if ($d['n'])<strong>{{ $d['n'] }}</strong>@endif
                                <i style="height: {{ max(4, round($d['n'] * 36 / $p['dayMax'])) }}px"></i>
                                <em>{{ $d['label'] }}</em>
                            </div>
                        @endforeach
                    </div>

                    <div class="adr-foot">
                        @if ($p['first'])<span>بدأ <b>{{ $p['first']->format('h:i A') }}</b></span>@endif
                        @if ($p['last'])<span>آخر حاجة <b>{{ $p['last']->format('h:i A') }}</b></span>@endif
                        <button type="button" class="adr-more" wire:click="toggle({{ $p['id'] }})">
                            {{ $open === $p['id'] ? 'اقفل الأسماء' : 'الأسماء والتفاصيل' }}
                        </button>
                    </div>

                    @if ($open === $p['id'])
                        <div class="adr-list">
                            @if ($p['rows']->isNotEmpty())
                                <h4>📤 الطلبات اللي اشتغل عليها ({{ $p['rows']->count() }})</h4>
                                @foreach ($p['rows'] as $r)
                                    <div class="adr-row">
                                        <div class="adr-time">{{ $r['at']->format('h:i A') }}</div>
                                        <div class="adr-name">
                                            @if ($r['url'])<a href="{{ $r['url'] }}" target="_blank">{{ $r['name'] }}</a>@else{{ $r['name'] }}@endif
                                            <small>
                                                طلب #{{ $r['request_id'] }}
                                                @if ($r['ref']) · رقم أمان {{ $r['ref'] }} @endif
                                                @if ($r['minutes']) · {{ $r['minutes'] }} دقيقة @endif
                                                @if ($r['message'] && $r['status'] !== 'uploaded') · {{ \Illuminate\Support\Str::limit($r['message'], 90) }} @endif
                                            </small>
                                        </div>
                                        <span class="adr-chip c-{{ $results[$r['status']][1] ?? 'muted' }}">{{ $results[$r['status']][0] ?? $r['status'] }}</span>
                                    </div>
                                @endforeach
                            @endif
                            @if ($p['checkRows']->isNotEmpty())
                                <h4>🔎 متابعة الاستعلام ({{ $p['checkRows']->count() }})</h4>
                                @foreach ($p['checkRows'] as $r)
                                    <div class="adr-row">
                                        <div class="adr-time">{{ $r['at']->format('h:i A') }}</div>
                                        <div class="adr-name">
                                            <a href="{{ $r['url'] }}" target="_blank">{{ $r['name'] }}</a>
                                            <small>أمان قال: {{ \Illuminate\Support\Str::limit($r['text'], 110) }}</small>
                                        </div>
                                        @if ($r['changed'])
                                            <span class="adr-chip c-good">بقى {{ $statusLabels[$r['status']] ?? $r['status'] }}</span>
                                        @elseif ($r['status'])
                                            <span class="adr-chip c-muted">لسه {{ $statusLabels[$r['status']] ?? $r['status'] }}</span>
                                        @else
                                            <span class="adr-chip c-warn">حالة مش متعلّمة</span>
                                        @endif
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
</x-filament-widgets::widget>
