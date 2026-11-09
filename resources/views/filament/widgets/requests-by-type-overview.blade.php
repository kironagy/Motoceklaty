<x-filament-widgets::widget>
    <style>
        .rq { direction: rtl; --rq-ink: #0f172a; --rq-muted: #64748b; --rq-card: rgba(255, 255, 255, .78); --rq-line: rgba(148, 163, 184, .22); --rq-soft: #f8fafc; }
        .dark .rq { --rq-ink: #f1f5f9; --rq-muted: #94a3b8; --rq-card: rgba(15, 23, 42, .72); --rq-line: rgba(148, 163, 184, .14); --rq-soft: rgba(30, 41, 59, .6); }

        .rq-shell { position: relative; overflow: hidden; border-radius: 28px; padding: 22px; background:
            radial-gradient(900px 260px at 100% -10%, rgba(99, 102, 241, .16), transparent 60%),
            radial-gradient(700px 240px at -10% 110%, rgba(6, 182, 212, .14), transparent 60%),
            linear-gradient(180deg, #fbfcff, #f4f6fb); border: 1px solid var(--rq-line); }
        .dark .rq-shell { background:
            radial-gradient(900px 260px at 100% -10%, rgba(99, 102, 241, .22), transparent 60%),
            radial-gradient(700px 240px at -10% 110%, rgba(6, 182, 212, .16), transparent 60%),
            linear-gradient(180deg, #0b1120, #0f172a); }

        .rq-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 18px; }
        .rq-title { display: flex; align-items: center; gap: 12px; }
        .rq-badge { width: 46px; height: 46px; border-radius: 16px; display: grid; place-items: center; color: #fff;
            background: linear-gradient(135deg, #6366f1, #06b6d4); box-shadow: 0 10px 24px rgba(99, 102, 241, .35); }
        .rq-badge svg { width: 24px; height: 24px; }
        .rq-title h2 { margin: 0; font-size: 20px; font-weight: 900; color: var(--rq-ink); letter-spacing: -.01em; }
        .rq-title p { margin: 2px 0 0; font-size: 13px; font-weight: 600; color: var(--rq-muted); }

        .rq-month { min-width: 240px; flex: 0 1 320px; padding: 10px 14px; border-radius: 18px; background: var(--rq-card); border: 1px solid var(--rq-line); backdrop-filter: blur(8px); }
        .rq-month-top { display: flex; justify-content: space-between; font-size: 12px; font-weight: 700; color: var(--rq-muted); }
        .rq-month-top b { color: var(--rq-ink); font-size: 14px; }
        .rq-track { margin-top: 8px; height: 8px; border-radius: 99px; background: var(--rq-line); overflow: hidden; }
        .rq-track i { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #06b6d4, #6366f1); }

        .rq-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
        @media (max-width: 1100px) { .rq-grid { grid-template-columns: 1fr; } }

        .rq-card { border-radius: 24px; padding: 18px; background: var(--rq-card); border: 1px solid var(--rq-line); backdrop-filter: blur(10px);
            box-shadow: 0 1px 0 rgba(255, 255, 255, .6) inset, 0 18px 40px -24px rgba(15, 23, 42, .35); }

        .rq-card-top { display: flex; align-items: center; gap: 18px; }
        .rq-donut { --size: 118px; flex: none; width: var(--size); height: var(--size); border-radius: 50%; display: grid; place-items: center; position: relative; transition: transform .4s ease; }
        .rq-card:hover .rq-donut { transform: rotate(-8deg) scale(1.03); }
        .rq-donut::after { content: ""; position: absolute; inset: 13px; border-radius: 50%; background: #fff; box-shadow: inset 0 2px 10px rgba(15, 23, 42, .08); }
        .dark .rq-donut::after { background: #0f172a; }
        .rq-donut-in { position: relative; z-index: 1; text-align: center; transition: transform .4s ease; }
        .rq-card:hover .rq-donut-in { transform: rotate(8deg); }
        .rq-donut-in strong { display: block; font-size: 30px; line-height: 1; font-weight: 900; color: var(--rq-ink); }
        .rq-donut-in span { font-size: 11px; font-weight: 700; color: var(--rq-muted); }

        .rq-card-info { min-width: 0; flex: 1; }
        .rq-type { display: inline-flex; align-items: center; gap: 8px; text-decoration: none; color: var(--rq-ink); font-size: 18px; font-weight: 900; }
        .rq-type svg { width: 22px; height: 22px; color: var(--accent); }
        .rq-type:hover { color: var(--accent); }
        .rq-sub { margin: 6px 0 10px; font-size: 13px; color: var(--rq-muted); font-weight: 600; }
        .rq-chip { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 99px; font-size: 12px; font-weight: 800; }
        .rq-chip svg { width: 14px; height: 14px; }
        .rq-up { color: #047857; background: rgba(16, 185, 129, .12); }
        .rq-down { color: #be123c; background: rgba(244, 63, 94, .12); }
        .rq-flat { color: var(--rq-muted); background: var(--rq-line); }
        .dark .rq-up { color: #6ee7b7; } .dark .rq-down { color: #fda4af; }

        .rq-list { margin-top: 16px; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        @media (max-width: 520px) { .rq-list { grid-template-columns: 1fr; } }
        .rq-item { --c: #6366f1; display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 16px; text-decoration: none;
            background: color-mix(in srgb, var(--c) 7%, var(--rq-soft)); border: 1px solid color-mix(in srgb, var(--c) 18%, transparent);
            transition: transform .2s ease, box-shadow .2s ease, background .2s ease; }
        .rq-item:hover { transform: translateY(-2px); background: color-mix(in srgb, var(--c) 13%, var(--rq-soft)); box-shadow: 0 12px 24px -16px var(--c); }
        .rq-ico { flex: none; width: 34px; height: 34px; border-radius: 12px; display: grid; place-items: center; color: var(--c); background: color-mix(in srgb, var(--c) 16%, transparent); }
        .rq-ico svg { width: 18px; height: 18px; }
        .rq-body { min-width: 0; flex: 1; }
        .rq-row { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
        .rq-label { font-size: 13px; font-weight: 800; color: var(--rq-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .rq-count { font-size: 20px; font-weight: 900; color: var(--c); line-height: 1; font-variant-numeric: tabular-nums; }
        .rq-bar { margin-top: 6px; height: 5px; border-radius: 99px; background: color-mix(in srgb, var(--c) 14%, transparent); overflow: hidden; }
        .rq-bar i { display: block; height: 100%; border-radius: inherit; background: var(--c); }
        .rq-zero { opacity: .55; }
    </style>

    <div class="rq">
        <div class="rq-shell">
            <div class="rq-head">
                <div class="rq-title">
                    <div class="rq-badge"><x-filament::icon icon="heroicon-o-chart-pie" /></div>
                    <div>
                        <h2>طلبات شهر {{ $month }}</h2>
                        <p>العداد بيبدأ من الصفر أول كل شهر</p>
                    </div>
                </div>

                <div class="rq-month">
                    <div class="rq-month-top">
                        <span>يوم <b>{{ $dayOfMonth }}</b> من {{ $daysInMonth }}</span>
                        <span>{{ $daysLeft === 0 ? 'آخر يوم في الشهر' : 'باقي '.$daysLeft.' يوم على التصفير' }}</span>
                    </div>
                    <div class="rq-track"><i style="width: {{ round($dayOfMonth / $daysInMonth * 100) }}%"></i></div>
                </div>
            </div>

            <div class="rq-grid">
                @foreach ($types as $type)
                    <div class="rq-card" style="--accent: {{ $type['accent'] }}">
                        <div class="rq-card-top">
                            <div class="rq-donut" style="background: {{ $type['total'] > 0 ? 'conic-gradient('.implode(', ', $type['slices']).')' : 'conic-gradient(rgba(148,163,184,.25) 0 100%)' }}">
                                <div class="rq-donut-in">
                                    <strong>{{ number_format($type['total']) }}</strong>
                                    <span>طلب</span>
                                </div>
                            </div>

                            <div class="rq-card-info">
                                <a class="rq-type" href="{{ $type['url'] }}">
                                    <x-filament::icon :icon="$type['icon']" />
                                    {{ $type['label'] }}
                                </a>
                                <p class="rq-sub">اتقدمت من أول الشهر لحد النهارده</p>

                                @if ($type['delta'] > 0)
                                    <span class="rq-chip rq-up"><x-filament::icon icon="heroicon-m-arrow-trending-up" /> أكتر بـ {{ number_format($type['delta']) }} من نفس الفترة الشهر اللي فات</span>
                                @elseif ($type['delta'] < 0)
                                    <span class="rq-chip rq-down"><x-filament::icon icon="heroicon-m-arrow-trending-down" /> أقل بـ {{ number_format(abs($type['delta'])) }} من نفس الفترة الشهر اللي فات</span>
                                @else
                                    <span class="rq-chip rq-flat"><x-filament::icon icon="heroicon-m-minus" /> زي نفس الفترة الشهر اللي فات</span>
                                @endif
                            </div>
                        </div>

                        <div class="rq-list">
                            @foreach ($type['rows'] as $row)
                                <a class="rq-item {{ $row['count'] === 0 ? 'rq-zero' : '' }}" style="--c: {{ $row['color'] }}" href="{{ $row['url'] }}">
                                    <span class="rq-ico"><x-filament::icon :icon="$row['icon']" /></span>
                                    <span class="rq-body">
                                        <span class="rq-row">
                                            <span class="rq-label">{{ $row['label'] }}</span>
                                            <span class="rq-count">{{ number_format($row['count']) }}</span>
                                        </span>
                                        <span class="rq-bar" style="display:block"><i style="width: {{ $type['total'] > 0 ? round($row['count'] / $type['total'] * 100, 1) : 0 }}%"></i></span>
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
