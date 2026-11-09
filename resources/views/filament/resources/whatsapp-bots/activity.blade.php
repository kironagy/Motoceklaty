<x-filament-panels::page>
    @php
        $initials = mb_substr(trim($bot->name), 0, 2);
        $connected = $bot->session_status === 'connected';
        $statusLabel = match ($bot->session_status) {
            'connected' => 'متصل دلوقتي', 'qr' => 'مستني مسح QR', 'starting' => 'بيشتغل',
            'disconnected' => 'مفصول', 'logged_out' => 'خرج من واتساب', default => 'مش مربوط',
        };
        $maxHour = max(1, collect($hourly)->max(fn ($h) => $h['incoming'] + $h['outgoing']));
        $share = fn ($n) => $summary['messages'] > 0 ? round($n / $summary['messages'] * 100, 1) : 0;
        $fmt = fn ($n) => $n >= 1000000 ? round($n / 1000000, 1).'M' : ($n >= 1000 ? round($n / 1000, 1).'K' : number_format($n));
    @endphp

    <div class="ba" dir="rtl" @if ($isToday) wire:poll.30s @endif>
        <style>
            .ba { --ink: #0f172a; --muted: #64748b; --card: #ffffff; --soft: #f8fafc; --line: rgba(148, 163, 184, .24); --in: #6366f1; --out: #10b981; --staff: #f59e0b; display: grid; gap: 18px; }
            .dark .ba { --ink: #f1f5f9; --muted: #94a3b8; --card: #0f172a; --soft: #111c31; --line: rgba(148, 163, 184, .14); }
            .ba-card { background: var(--card); border: 1px solid var(--line); border-radius: 24px; box-shadow: 0 18px 40px -30px rgba(15, 23, 42, .45); }

            .ba-hero { position: relative; overflow: hidden; padding: 22px; display: flex; flex-wrap: wrap; gap: 18px; align-items: center; justify-content: space-between;
                background: radial-gradient(600px 220px at 100% 0, rgba(99, 102, 241, .18), transparent 60%), radial-gradient(500px 200px at 0 100%, rgba(16, 185, 129, .16), transparent 60%), var(--card); }
            .ba-id { display: flex; align-items: center; gap: 16px; min-width: 0; }
            .ba-avatar { position: relative; flex: none; width: 64px; height: 64px; border-radius: 22px; display: grid; place-items: center; font-size: 22px; font-weight: 900; color: #fff;
                background: linear-gradient(135deg, #6366f1, #10b981); box-shadow: 0 14px 30px -10px rgba(99, 102, 241, .6); }
            .ba-dot { position: absolute; bottom: -3px; left: -3px; width: 18px; height: 18px; border-radius: 50%; border: 3px solid var(--card); background: #94a3b8; }
            .ba-dot.on { background: #22c55e; } .ba-dot.on::after { content: ""; position: absolute; inset: -6px; border-radius: 50%; border: 2px solid #22c55e; animation: ba-ping 1.8s ease-out infinite; }
            @keyframes ba-ping { from { transform: scale(.6); opacity: .9; } to { transform: scale(1.5); opacity: 0; } }
            .ba-id h2 { margin: 0; font-size: 22px; font-weight: 900; color: var(--ink); }
            .ba-meta { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
            .ba-pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 99px; font-size: 12px; font-weight: 700; color: var(--muted); background: var(--soft); border: 1px solid var(--line); }
            .ba-pill svg { width: 14px; height: 14px; }
            .ba-pill.ok { color: #047857; background: rgba(16, 185, 129, .1); border-color: rgba(16, 185, 129, .25); }
            .dark .ba-pill.ok { color: #6ee7b7; }

            .ba-day { display: flex; align-items: center; gap: 8px; padding: 6px; border-radius: 18px; background: var(--soft); border: 1px solid var(--line); }
            .ba-day button { width: 36px; height: 36px; border-radius: 12px; display: grid; place-items: center; color: var(--ink); background: var(--card); border: 1px solid var(--line); transition: .2s; }
            .ba-day button:hover:not(:disabled) { color: var(--in); transform: translateY(-1px); }
            .ba-day button:disabled { opacity: .35; cursor: not-allowed; }
            .ba-day button svg { width: 18px; height: 18px; }
            .ba-day-label { min-width: 120px; text-align: center; }
            .ba-day-label b { display: block; font-size: 15px; font-weight: 900; color: var(--ink); }
            .ba-day-label input { margin-top: 2px; border: 0; background: transparent; font-size: 12px; color: var(--muted); text-align: center; padding: 0; }
            .ba-day-label input:focus { outline: none; box-shadow: none; }

            .ba-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
            @media (max-width: 1100px) { .ba-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
            @media (max-width: 560px) { .ba-kpis { grid-template-columns: 1fr; } }
            .ba-kpi { --c: #6366f1; padding: 16px; position: relative; overflow: hidden; }
            .ba-kpi::before { content: ""; position: absolute; inset: auto -30px -40px auto; width: 120px; height: 120px; border-radius: 50%; background: color-mix(in srgb, var(--c) 12%, transparent); }
            .ba-kpi-top { display: flex; align-items: center; justify-content: space-between; }
            .ba-kpi-label { font-size: 13px; font-weight: 800; color: var(--muted); }
            .ba-kpi-ico { width: 38px; height: 38px; border-radius: 13px; display: grid; place-items: center; color: var(--c); background: color-mix(in srgb, var(--c) 14%, transparent); }
            .ba-kpi-ico svg { width: 20px; height: 20px; }
            .ba-kpi-value { position: relative; margin-top: 10px; font-size: 34px; line-height: 1; font-weight: 900; color: var(--ink); font-variant-numeric: tabular-nums; }
            .ba-kpi-value small { font-size: 14px; font-weight: 700; color: var(--muted); }
            .ba-kpi-sub { position: relative; margin-top: 8px; font-size: 12px; font-weight: 700; color: var(--muted); }
            .ba-split { position: relative; display: flex; height: 7px; margin-top: 10px; border-radius: 99px; overflow: hidden; background: var(--line); }
            .ba-split i { display: block; height: 100%; }
            .ba-legend { position: relative; display: flex; flex-wrap: wrap; gap: 10px; margin-top: 8px; font-size: 11px; font-weight: 700; color: var(--muted); }
            .ba-legend span::before { content: ""; display: inline-block; width: 8px; height: 8px; border-radius: 3px; margin-left: 4px; background: var(--c); }

            .ba-chips { display: flex; flex-wrap: wrap; gap: 10px; }
            .ba-chip { --c: #64748b; display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 16px; font-size: 13px; font-weight: 800; color: var(--ink); background: var(--card); border: 1px solid var(--line); }
            .ba-chip svg { width: 16px; height: 16px; color: var(--c); }
            .ba-chip b { color: var(--c); font-size: 15px; }

            .ba-chart { padding: 18px; }
            .ba-chart-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
            .ba-chart-head h3 { margin: 0; font-size: 16px; font-weight: 900; color: var(--ink); }
            .ba-bars { display: grid; grid-template-columns: repeat(24, minmax(0, 1fr)); gap: 6px; align-items: end; height: 140px; direction: ltr; }
            .ba-bar { position: relative; display: flex; flex-direction: column-reverse; height: 100%; border-radius: 8px; background: var(--soft); overflow: hidden; }
            .ba-bar i { display: block; transition: height .5s ease; }
            .ba-bar:hover { outline: 2px solid color-mix(in srgb, var(--in) 40%, transparent); }
            .ba-hours { display: grid; grid-template-columns: repeat(24, minmax(0, 1fr)); gap: 6px; margin-top: 6px; direction: ltr; font-size: 10px; font-weight: 700; color: var(--muted); text-align: center; }

            .ba-main { display: grid; grid-template-columns: minmax(0, 5fr) minmax(0, 7fr); gap: 18px; align-items: start; }
            @media (max-width: 1100px) { .ba-main { grid-template-columns: 1fr; } }
            .ba-list { padding: 14px; }
            .ba-list-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 4px 4px 12px; }
            .ba-list-head h3 { margin: 0; font-size: 16px; font-weight: 900; color: var(--ink); }
            .ba-search { position: relative; flex: 1; max-width: 220px; }
            .ba-search input { width: 100%; padding: 8px 34px 8px 12px; border-radius: 14px; border: 1px solid var(--line); background: var(--soft); color: var(--ink); font-size: 13px; }
            .ba-search svg { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: var(--muted); }
            .ba-convs { display: grid; gap: 8px; max-height: 720px; overflow-y: auto; padding: 2px; }
            .ba-conv { display: flex; gap: 12px; width: 100%; text-align: right; padding: 12px; border-radius: 18px; border: 1px solid transparent; background: var(--soft); transition: .2s; }
            .ba-conv:hover { border-color: var(--line); transform: translateX(-2px); }
            .ba-conv.active { background: color-mix(in srgb, var(--in) 9%, var(--card)); border-color: color-mix(in srgb, var(--in) 35%, transparent); }
            .ba-conv-av { flex: none; width: 44px; height: 44px; border-radius: 15px; display: grid; place-items: center; font-size: 14px; font-weight: 900; color: #fff; }
            .ba-conv-body { min-width: 0; flex: 1; }
            .ba-conv-top { display: flex; justify-content: space-between; gap: 8px; }
            .ba-conv-name { font-size: 14px; font-weight: 900; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .ba-conv-time { flex: none; font-size: 11px; font-weight: 700; color: var(--muted); }
            .ba-conv-preview { margin-top: 3px; font-size: 12px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .ba-conv-preview b { color: var(--ink); font-weight: 800; }
            .ba-conv-stats { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
            .ba-tag { --c: #64748b; display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 800; color: var(--c); background: color-mix(in srgb, var(--c) 12%, transparent); }
            .ba-tag svg { width: 12px; height: 12px; }

            .ba-chat { position: sticky; top: 80px; display: flex; flex-direction: column; height: 760px; overflow: hidden; }
            @media (max-width: 1100px) { .ba-chat { position: static; height: 640px; } }
            .ba-chat-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--line); }
            .ba-chat-head h3 { margin: 0; font-size: 15px; font-weight: 900; color: var(--ink); }
            .ba-chat-head p { margin: 2px 0 0; font-size: 12px; color: var(--muted); }
            .ba-x { width: 34px; height: 34px; border-radius: 12px; display: grid; place-items: center; color: var(--muted); background: var(--soft); }
            .ba-x svg { width: 18px; height: 18px; }
            .ba-canvas { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 6px;
                background-color: var(--soft); background-image: radial-gradient(color-mix(in srgb, var(--in) 10%, transparent) 1px, transparent 1px); background-size: 18px 18px; }
            .ba-divider { align-self: center; margin: 10px 0; padding: 3px 12px; border-radius: 99px; font-size: 11px; font-weight: 800; color: var(--muted); background: var(--card); border: 1px solid var(--line); }
            .ba-row { display: flex; }
            .ba-row.in { justify-content: flex-start; } .ba-row.out { justify-content: flex-end; } .ba-row.sys { justify-content: center; }
            .ba-bubble { max-width: min(78%, 460px); padding: 8px 12px 6px; border-radius: 18px; font-size: 14px; line-height: 1.7; color: var(--ink); box-shadow: 0 2px 6px -3px rgba(15, 23, 42, .25); word-break: break-word; }
            .ba-text { white-space: pre-line; }
            .ba-row.in .ba-bubble { background: var(--card); border-top-right-radius: 6px; }
            .ba-row.out .ba-bubble { background: color-mix(in srgb, var(--out) 16%, var(--card)); border-top-left-radius: 6px; }
            .ba-row.out.staff .ba-bubble { background: color-mix(in srgb, var(--staff) 18%, var(--card)); }
            .ba-row.sys .ba-bubble { max-width: 90%; font-size: 12px; color: var(--muted); background: var(--card); border: 1px dashed var(--line); box-shadow: none; }
            .ba-who { display: flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 900; margin-bottom: 2px; }
            .ba-who svg { width: 13px; height: 13px; }
            .ba-row.out .ba-who { color: #059669; } .ba-row.out.staff .ba-who { color: #b45309; }
            .dark .ba-row.out .ba-who { color: #6ee7b7; } .dark .ba-row.out.staff .ba-who { color: #fcd34d; }
            .ba-media img { max-width: 240px; max-height: 240px; border-radius: 12px; display: block; object-fit: cover; margin-bottom: 4px; }
            .ba-media video { max-width: 260px; border-radius: 12px; display: block; margin-bottom: 4px; }
            .ba-media audio { width: 240px; height: 36px; margin-bottom: 4px; }
            .ba-file { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 700; color: var(--in); }
            .ba-transcript { margin-top: 2px; padding: 6px 10px; border-radius: 12px; font-size: 13px; background: var(--soft); border-right: 3px solid var(--in); }
            .ba-time { display: flex; justify-content: flex-end; align-items: center; gap: 4px; margin-top: 2px; font-size: 10px; font-weight: 700; color: var(--muted); }
            .ba-time svg { width: 13px; height: 13px; }
            .ba-empty { display: grid; place-items: center; gap: 10px; height: 100%; min-height: 260px; text-align: center; color: var(--muted); font-size: 14px; font-weight: 700; padding: 24px; }
            .ba-empty-ico { width: 72px; height: 72px; border-radius: 26px; display: grid; place-items: center; color: var(--in); background: color-mix(in srgb, var(--in) 12%, transparent); }
            .ba-empty-ico svg { width: 34px; height: 34px; }
        </style>

        {{-- البوت واليوم --}}
        <div class="ba-card ba-hero">
            <div class="ba-id">
                <div class="ba-avatar">{{ $initials }}<span class="ba-dot {{ $connected ? 'on' : '' }}"></span></div>
                <div>
                    <h2>{{ $bot->name }}</h2>
                    <div class="ba-meta">
                        <span class="ba-pill {{ $connected ? 'ok' : '' }}"><x-filament::icon icon="heroicon-m-signal" /> {{ $statusLabel }}</span>
                        @if ($bot->whatsapp_phone_number)
                            <span class="ba-pill"><x-filament::icon icon="heroicon-m-phone" /> <span dir="ltr">{{ $bot->whatsapp_phone_number }}</span></span>
                        @endif
                        @if ($bot->staff)
                            <span class="ba-pill"><x-filament::icon icon="heroicon-m-user" /> {{ $bot->staff->name }}</span>
                        @endif
                        <span class="ba-pill"><x-filament::icon icon="{{ $bot->mode === 'training' ? 'heroicon-m-academic-cap' : 'heroicon-m-bolt' }}" /> {{ $bot->mode === 'training' ? 'تعليمي' : 'لايف' }}</span>
                    </div>
                </div>
            </div>

            <div class="ba-day">
                <button type="button" wire:click="shiftDay(-1)" title="اليوم اللي قبله"><x-filament::icon icon="heroicon-m-chevron-right" /></button>
                <div class="ba-day-label">
                    <b>{{ $dayLabel }}</b>
                    <input type="date" wire:model.live="day" max="{{ today()->toDateString() }}" />
                </div>
                <button type="button" wire:click="shiftDay(1)" @disabled($isToday) title="اليوم اللي بعده"><x-filament::icon icon="heroicon-m-chevron-left" /></button>
            </div>
        </div>

        {{-- الأرقام الأساسية --}}
        <div class="ba-kpis">
            <div class="ba-card ba-kpi" style="--c: #6366f1">
                <div class="ba-kpi-top"><span class="ba-kpi-label">محادثات رد عليها</span><span class="ba-kpi-ico"><x-filament::icon icon="heroicon-o-chat-bubble-left-right" /></span></div>
                <div class="ba-kpi-value">{{ number_format($summary['answered']) }} <small>من {{ number_format($summary['conversations']) }}</small></div>
                <div class="ba-kpi-sub">{{ number_format($summary['new_chats']) }} عميل جديد كلّم البوت لأول مرة</div>
            </div>

            <div class="ba-card ba-kpi" style="--c: #10b981">
                <div class="ba-kpi-top"><span class="ba-kpi-label">الرسايل</span><span class="ba-kpi-ico"><x-filament::icon icon="heroicon-o-envelope" /></span></div>
                <div class="ba-kpi-value">{{ number_format($summary['messages']) }}</div>
                <div class="ba-split">
                    <i style="width: {{ $share($summary['incoming']) }}%; background: var(--in)"></i>
                    <i style="width: {{ $share($summary['bot']) }}%; background: var(--out)"></i>
                    <i style="width: {{ $share($summary['staff']) }}%; background: var(--staff)"></i>
                </div>
                <div class="ba-legend">
                    <span style="--c: var(--in)">العملاء {{ number_format($summary['incoming']) }}</span>
                    <span style="--c: var(--out)">البوت {{ number_format($summary['bot']) }}</span>
                    <span style="--c: var(--staff)">الموظفين {{ number_format($summary['staff']) }}</span>
                </div>
            </div>

            <div class="ba-card ba-kpi" style="--c: #f59e0b">
                <div class="ba-kpi-top"><span class="ba-kpi-label">متوسط الرسايل لكل محادثة</span><span class="ba-kpi-ico"><x-filament::icon icon="heroicon-o-chart-bar" /></span></div>
                <div class="ba-kpi-value">{{ $summary['avg_per_chat'] }} <small>رسالة</small></div>
                <div class="ba-kpi-sub">{{ $summary['avg_latency'] ? 'البوت بيرد في حوالي '.$summary['avg_latency'].' ثانية' : 'مفيش ردود من الـ AI في اليوم ده' }}</div>
            </div>

            <div class="ba-card ba-kpi" style="--c: #a855f7">
                <div class="ba-kpi-top"><span class="ba-kpi-label">استهلاك الذكاء الاصطناعي</span><span class="ba-kpi-ico"><x-filament::icon icon="heroicon-o-cpu-chip" /></span></div>
                <div class="ba-kpi-value">{{ number_format($summary['ai_calls']) }} <small>طلب</small></div>
                <div class="ba-kpi-sub">{{ $fmt($summary['tokens']) }} توكن في اليوم ده</div>
            </div>
        </div>

        <div class="ba-chips">
            <span class="ba-chip" style="--c: #6366f1"><x-filament::icon icon="heroicon-o-microphone" /> رسايل صوتية <b>{{ number_format($summary['voice']) }}</b></span>
            <span class="ba-chip" style="--c: #06b6d4"><x-filament::icon icon="heroicon-o-photo" /> صور وملفات من العملاء <b>{{ number_format($summary['files']) }}</b></span>
            <span class="ba-chip" style="--c: #f59e0b"><x-filament::icon icon="heroicon-o-user-group" /> ردود الموظفين <b>{{ number_format($summary['staff']) }}</b></span>
        </div>

        {{-- النشاط على مدار اليوم --}}
        <div class="ba-card ba-chart">
            <div class="ba-chart-head">
                <h3>النشاط على مدار اليوم</h3>
                <div class="ba-legend" style="margin: 0">
                    <span style="--c: var(--in)">رسايل العملاء</span>
                    <span style="--c: var(--out)">ردود البوت والموظفين</span>
                </div>
            </div>
            <div class="ba-bars">
                @foreach ($hourly as $h)
                    <div class="ba-bar" title="الساعة {{ $h['hour'] }}:00 — {{ $h['incoming'] }} من العملاء، {{ $h['outgoing'] }} ردود">
                        <i style="height: {{ round($h['incoming'] / $maxHour * 100, 1) }}%; background: var(--in)"></i>
                        <i style="height: {{ round($h['outgoing'] / $maxHour * 100, 1) }}%; background: var(--out)"></i>
                    </div>
                @endforeach
            </div>
            <div class="ba-hours">
                @foreach ($hourly as $h)
                    <span>{{ $h['hour'] % 3 === 0 ? ($h['hour'] === 0 ? '12ص' : ($h['hour'] < 12 ? $h['hour'].'ص' : ($h['hour'] === 12 ? '12م' : ($h['hour'] - 12).'م'))) : '' }}</span>
                @endforeach
            </div>
        </div>

        {{-- المحادثات والشات --}}
        <div class="ba-main">
            <div class="ba-card ba-list">
                <div class="ba-list-head">
                    <h3>محادثات {{ $dayLabel }} ({{ number_format($conversations->count()) }})</h3>
                    <label class="ba-search">
                        <x-filament::icon icon="heroicon-m-magnifying-glass" />
                        <input type="search" wire:model.live.debounce.400ms="search" placeholder="رقم أو اسم العميل" />
                    </label>
                </div>

                <div class="ba-convs">
                    @forelse ($conversations as $c)
                        @php
                            $hue = ($c['id'] * 47) % 360;
                            $label = $c['name'] ?: $c['phone'];
                        @endphp
                        <button type="button" wire:key="conv-{{ $c['id'] }}" wire:click="openChat({{ $c['id'] }})" class="ba-conv {{ $chat === $c['id'] ? 'active' : '' }}">
                            <span class="ba-conv-av" style="background: linear-gradient(135deg, hsl({{ $hue }} 70% 60%), hsl({{ ($hue + 40) % 360 }} 70% 50%))">{{ mb_substr($c['name'] ?: substr($c['phone'], -2), 0, 2) }}</span>
                            <span class="ba-conv-body">
                                <span class="ba-conv-top">
                                    <span class="ba-conv-name">{{ $label }} @if ($c['name'])<span style="font-weight:600;color:var(--muted);font-size:11px" dir="ltr">· {{ $c['phone'] }}</span>@endif</span>
                                    <span class="ba-conv-time">{{ $c['last_at']->format('h:i A') }}</span>
                                </span>
                                <span class="ba-conv-preview" style="display:block">
                                    <b>{{ ['customer' => 'العميل', 'bot' => 'البوت', 'staff' => 'موظف'][$c['last_from']] }}:</b> {{ \Illuminate\Support\Str::limit($c['preview'], 80) }}
                                </span>
                                <span class="ba-conv-stats">
                                    <span class="ba-tag" style="--c: #64748b"><x-filament::icon icon="heroicon-m-chat-bubble-oval-left" /> {{ $c['messages'] }} رسالة</span>
                                    <span class="ba-tag" style="--c: #6366f1">العميل {{ $c['incoming'] }}</span>
                                    <span class="ba-tag" style="--c: #059669">البوت {{ $c['bot'] }}</span>
                                    @if ($c['staff'])<span class="ba-tag" style="--c: #d97706">موظف {{ $c['staff'] }}</span>@endif
                                    @if ($c['ai_calls'])<span class="ba-tag" style="--c: #9333ea"><x-filament::icon icon="heroicon-m-cpu-chip" /> {{ $c['ai_calls'] }} · {{ $fmt($c['tokens']) }}</span>@endif
                                    @if ($c['status'] === 'awaiting_agent')<span class="ba-tag" style="--c: #e11d48">محوّلة لموظف</span>@endif
                                </span>
                            </span>
                        </button>
                    @empty
                        <div class="ba-empty">
                            <span class="ba-empty-ico"><x-filament::icon icon="heroicon-o-moon" /></span>
                            {{ trim($search) !== '' ? 'مفيش محادثة بالبحث ده' : 'البوت ما كلمش حد في اليوم ده' }}
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="ba-card ba-chat" id="ba-chat">
                @if ($chat)
                    <div class="ba-chat-head">
                        <div>
                            <h3>{{ $selected['name'] ?? ($selected['phone'] ?? 'محادثة #'.$chat) }}</h3>
                            <p>
                                @if ($selected)
                                    <span dir="ltr">{{ $selected['phone'] }}</span> · {{ $selected['messages'] }} رسالة في اليوم ده
                                    @if ($selected['ai_calls']) · {{ $selected['ai_calls'] }} طلب AI ({{ $fmt($selected['tokens']) }} توكن) @endif
                                @else
                                    المحادثة كاملة
                                @endif
                            </p>
                        </div>
                        <button type="button" class="ba-x" wire:click="closeChat" title="قفل"><x-filament::icon icon="heroicon-m-x-mark" /></button>
                    </div>

                    <div class="ba-canvas" id="ba-canvas" x-data x-init="$el.scrollTop = $el.scrollHeight" x-on:bot-chat-opened.window="$nextTick(() => $el.scrollTop = $el.scrollHeight)">
                        @php $lastDate = null; @endphp
                        @forelse ($chatMessages as $m)
                            @php
                                $kind = $m->direction === 'incoming' ? 'in' : ($m->sender_type === 'system' ? 'sys' : 'out');
                                $isStaff = $kind === 'out' && $m->sender_type !== 'bot';
                                $text = trim((string) ($m->text ?: $m->message));
                                $media = $activity->mediaFor($m);
                                $date = $m->created_at?->toDateString();
                            @endphp
                            @if ($date && $date !== $lastDate)
                                <div class="ba-divider">{{ $m->created_at->isToday() ? 'النهارده' : ($m->created_at->isYesterday() ? 'امبارح' : $m->created_at->copy()->locale('ar')->translatedFormat('j F Y')) }}</div>
                                @php $lastDate = $date; @endphp
                            @endif
                            <div class="ba-row {{ $kind }} {{ $isStaff ? 'staff' : '' }}" wire:key="msg-{{ $m->id }}">
                                <div class="ba-bubble">
                                    @if ($kind === 'out')
                                        <div class="ba-who">
                                            @if ($isStaff)<x-filament::icon icon="heroicon-m-user" /> موظف @else<x-filament::icon icon="heroicon-m-sparkles" /> البوت @endif
                                        </div>
                                    @endif

                                    @foreach ($media as $item)
                                        <div class="ba-media">
                                            @if ($item['type'] === 'image' || str_starts_with($item['mime'], 'image/'))
                                                <a href="{{ $item['url'] }}" target="_blank"><img src="{{ $item['url'] }}" loading="lazy" alt="صورة"></a>
                                            @elseif ($item['type'] === 'video' || str_starts_with($item['mime'], 'video/'))
                                                <video src="{{ $item['url'] }}" controls preload="none"></video>
                                            @elseif ($item['type'] === 'audio' || str_starts_with($item['mime'], 'audio/'))
                                                <audio src="{{ $item['url'] }}" controls preload="none"></audio>
                                            @else
                                                <a class="ba-file" href="{{ $item['url'] }}" target="_blank"><x-filament::icon icon="heroicon-m-paper-clip" class="h-4 w-4" /> {{ $item['name'] ?? 'ملف' }}</a>
                                            @endif
                                        </div>
                                    @endforeach

                                    @if ($text !== '' && $text !== '[media]')
                                        <div class="ba-text">{{ $text }}</div>
                                    @elseif (empty($media) && ! $m->transcript)
                                        <div style="color: var(--muted)">{{ $activity->preview($m) }}</div>
                                    @endif

                                    @if ($m->transcript)
                                        <div class="ba-transcript">🎙️ {{ $m->transcript }}</div>
                                    @endif

                                    <div class="ba-time">
                                        {{ $m->created_at?->format('h:i A') }}
                                        @if ($kind === 'out')
                                            @if (in_array($m->delivery_status, ['read', 'played'], true))
                                                <x-filament::icon icon="heroicon-m-check-badge" style="color:#3b82f6" />
                                            @elseif (in_array($m->delivery_status, ['failed', 'error'], true))
                                                <x-filament::icon icon="heroicon-m-exclamation-circle" style="color:#e11d48" />
                                            @else
                                                <x-filament::icon icon="heroicon-m-check" />
                                            @endif
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="ba-empty">مفيش رسايل في المحادثة دي</div>
                        @endforelse
                    </div>
                @else
                    <div class="ba-empty">
                        <span class="ba-empty-ico"><x-filament::icon icon="heroicon-o-chat-bubble-bottom-center-text" /></span>
                        <div>اختار محادثة من القايمة<br><span style="font-weight:600;font-size:13px">وهتشوف الشات بين العميل والبوت زي ما هو بالظبط</span></div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
