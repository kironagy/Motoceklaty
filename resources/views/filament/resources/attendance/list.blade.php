{{-- Attendance: a card per employee for the month (App\Filament\Resources\AttendanceResource\Pages\ListAttendances),
     or the table of days. Palette follows resources/css/filament/admin/theme.css. --}}
<x-filament-panels::page
    @class([
        'fi-resource-list-records-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    @php
        $cards = $this->mode === 'cards' ? $this->cards : collect();
        $stateLabels = [
            'green' => 'نقطة خضرا', 'red' => 'نقطة حمرا', 'black' => 'نقطة سودا', 'canceled' => 'نقطة اتلغت',
            'present' => 'حضر', 'absent' => 'غياب', 'vacation' => 'إجازة', 'off' => 'إجازة أسبوعية', 'future' => '', 'none' => '',
        ];
        $months = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
        $monthLabel = $months[$this->monthDate->month - 1].' '.$this->monthDate->year;
    @endphp

    <style>
        .at { --at-grad: linear-gradient(135deg, #2563eb, #7c3aed); --at-blue: #2563eb; --at-amber: #d97706;
              --at-card: #fff; --at-line: #e5e7eb; --at-soft: #f8fafc; --at-text: #111827; --at-muted: #6b7280;
              --at-green: #10b981; --at-red: #ef4444; --at-black: #111827; --at-black-ring: transparent; --at-vac: #60a5fa;
              --at-shadow: 0 12px 30px rgba(15,23,42,.08); }
        .dark .at { --at-amber: #fbbf24; --at-card: #1C293E; --at-line: rgba(255,255,255,.08); --at-soft: rgba(255,255,255,.04);
              --at-text: #f8fafc; --at-muted: rgba(229,231,235,.6); --at-black: #020617; --at-black-ring: rgba(255,255,255,.35);
              --at-shadow: 0 18px 45px rgba(0,0,0,.35); }

        .at-bar { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem; justify-content: space-between; }
        .at-month { display: flex; align-items: center; gap: .3rem; background: var(--at-card); border: 1px solid var(--at-line); border-radius: 14px; padding: .25rem; box-shadow: var(--at-shadow); }
        .at-month b { min-width: 8.5rem; text-align: center; font-size: .95rem; font-weight: 800; color: var(--at-text); }
        .at-iconbtn { display: grid; place-items: center; width: 2.1rem; height: 2.1rem; border-radius: 10px; border: 0; background: transparent; color: var(--at-muted); cursor: pointer; }
        .at-iconbtn:hover { background: var(--at-soft); color: var(--at-text); }
        .at-iconbtn svg { width: 1.1rem; height: 1.1rem; }
        .at-tools { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
        .at-search, .at-sort { height: 2.6rem; border-radius: 12px; border: 1px solid var(--at-line); background-color: var(--at-card); color: var(--at-text); font-size: .875rem; padding: 0 .8rem; box-shadow: var(--at-shadow); outline: none; }
        .at-search { width: 13rem; }
        .at-sort { padding-inline-end: 2.4rem; background-repeat: no-repeat; background-position: left .7rem center; }
        .at-search:focus, .at-sort:focus { border-color: var(--at-blue); box-shadow: 0 0 0 3px rgba(37,99,235,.2); }
        .at-seg { display: inline-flex; padding: .25rem; border-radius: 12px; background: var(--at-card); border: 1px solid var(--at-line); box-shadow: var(--at-shadow); }
        .at-seg button { border: 0; background: transparent; color: var(--at-muted); font-weight: 700; font-size: .82rem; padding: .45rem .85rem; border-radius: 9px; cursor: pointer; }
        .at-seg button.on { background: var(--at-grad); color: #fff; box-shadow: 0 6px 16px rgba(37,99,235,.3); }

        .at-legend { display: flex; flex-wrap: wrap; gap: .9rem; font-size: .76rem; color: var(--at-muted); margin-top: .9rem; }
        .at-legend span { display: inline-flex; align-items: center; gap: .35rem; }

        .at-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 330px), 1fr)); gap: 1rem; margin-top: 1rem; }
        .at-card { position: relative; text-align: right; background: var(--at-card); border: 1px solid var(--at-line); border-radius: 20px; padding: 1.1rem 1.1rem 1rem;
                   box-shadow: var(--at-shadow); cursor: pointer; transition: border-color .15s, transform .15s; width: 100%; font: inherit; color: inherit; }
        .at-card:hover { border-color: rgba(37,99,235,.55); transform: translateY(-2px); }
        .at-card:focus-visible { outline: 3px solid rgba(37,99,235,.5); outline-offset: 2px; }

        .at-head { display: flex; align-items: center; gap: .8rem; }
        /* ring = on-time share; the avatar sits inside it */
        .at-ring { --p: 0; flex: none; width: 3.4rem; height: 3.4rem; border-radius: 50%; display: grid; place-items: center;
                   background: conic-gradient(var(--at-green) calc(var(--p) * 1%), var(--at-line) 0); }
        .at-ring.at-none { background: var(--at-line); }
        .at-avatar { width: 2.85rem; height: 2.85rem; border-radius: 50%; display: grid; place-items: center; background: var(--at-grad);
                     color: #fff; font-weight: 800; font-size: 1rem; border: 3px solid var(--at-card); }
        .at-who { flex: 1; min-width: 0; }
        .at-name { font-size: 1.02rem; font-weight: 800; color: var(--at-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .at-meta { font-size: .76rem; color: var(--at-muted); margin-top: .1rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .at-pct { text-align: center; flex: none; }
        .at-pct b { display: block; font-size: 1.15rem; font-weight: 800; color: var(--at-text); line-height: 1; }
        .at-pct small { font-size: .68rem; color: var(--at-muted); }

        .at-nums { display: grid; grid-template-columns: repeat(3, 1fr); gap: .5rem; margin-top: 1rem; }
        .at-num { background: var(--at-soft); border: 1px solid var(--at-line); border-radius: 14px; padding: .55rem .4rem; text-align: center; }
        .at-num b { display: block; font-size: 1.45rem; font-weight: 800; line-height: 1.1; color: var(--at-text); }
        .at-num small { font-size: .74rem; color: var(--at-muted); }
        .at-num.at-warn b { color: var(--at-amber); }
        .at-num.at-bad b { color: var(--at-red); }

        .at-points { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .75rem; }
        .at-pt { display: inline-flex; align-items: center; gap: .35rem; font-size: .8rem; font-weight: 700; color: var(--at-text);
                 padding: .28rem .65rem; border-radius: 999px; border: 1px solid var(--at-line); background: var(--at-soft); }
        .at-pt i { width: .62rem; height: .62rem; border-radius: 50%; display: inline-block; }
        .at-pt.at-muted { color: var(--at-muted); font-weight: 600; }

        .at-strip { display: grid; grid-template-columns: repeat(11, 1fr); gap: 4px; margin-top: .9rem; }
        .at-d { display: grid; place-items: center; aspect-ratio: 1; border-radius: 7px; font-size: .68rem; font-weight: 700; font-style: normal;
                color: var(--at-muted); background: var(--at-soft); border: 1px solid var(--at-line); }
        .at-d.p { background: rgba(16,185,129,.18); border-color: rgba(16,185,129,.55); color: var(--at-green); }
        .at-d.l { background: rgba(245,158,11,.18); border-color: rgba(245,158,11,.6); color: var(--at-amber); }
        .at-d.a { background: rgba(239,68,68,.16); border-color: rgba(239,68,68,.6); color: var(--at-red); }
        .at-d.n { opacity: .35; }
        .at-legend i { width: .85rem; height: .85rem; aspect-ratio: auto; font-size: 0; }
        .at-foot { display: flex; justify-content: space-between; gap: .5rem; margin-top: .8rem; padding-top: .7rem; border-top: 1px dashed var(--at-line); font-size: .76rem; color: var(--at-muted); }
        .at-foot b { color: var(--at-text); font-weight: 700; }

        .at-empty { margin-top: 1rem; text-align: center; padding: 2.5rem 1rem; border: 1.5px dashed var(--at-line); border-radius: 20px; color: var(--at-muted); }

        @media (max-width: 640px) { .at-search { width: 100%; } .at-tools { width: 100%; } .at-sort { flex: 1; } }
        @media (prefers-reduced-motion: reduce) { .at-card { transition: none; } .at-card:hover { transform: none; } }
    </style>

    <div class="at flex flex-col gap-y-6" dir="rtl" x-data="{ q: '', sort: 'name' }">
        <div class="at-bar">
            <div class="at-tools">
                <div class="at-seg" role="tablist" aria-label="طريقة العرض">
                    <button type="button" @class(['on' => $this->mode === 'cards']) wire:click="$set('mode', 'cards')">كاردات الموظفين</button>
                    <button type="button" @class(['on' => $this->mode === 'table']) wire:click="$set('mode', 'table')">جدول الأيام</button>
                </div>

                @if ($this->mode === 'cards')
                    <div class="at-month">
                        <button type="button" class="at-iconbtn" wire:click="shiftMonth(-1)" title="الشهر اللي فات">
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                        </button>
                        <b>{{ $monthLabel }}</b>
                        <button type="button" class="at-iconbtn" wire:click="shiftMonth(1)" title="الشهر اللي جاي">
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1-.02 1.06L8.832 10l3.938 3.71a.75.75 0 1 1-1.04 1.08l-4.5-4.25a.75.75 0 0 1 0-1.08l4.5-4.25a.75.75 0 0 1 1.06.02Z" clip-rule="evenodd"/></svg>
                        </button>
                    </div>
                @endif
            </div>

            @if ($this->mode === 'cards' && $cards->count() > 1)
                <div class="at-tools">
                    <input type="search" class="at-search" placeholder="دوّر على موظف" x-model="q">
                    <select class="at-sort" x-model="sort" aria-label="الترتيب">
                        <option value="name">بالاسم</option>
                        <option value="late">الأكتر تأخير</option>
                        <option value="absent">الأكتر غياب</option>
                        <option value="green">الأكتر خضرا</option>
                    </select>
                </div>
            @endif
        </div>

        @if ($this->mode === 'cards')
            <div>
                @if ($cards->isEmpty())
                    <div class="at-empty">مفيش موظفين بيبصموا في الشهر ده. فعّل «بيبصم وبيتحسب له مرتب» من صفحة الموظفين.</div>
                @else
                    <div class="at-legend" aria-hidden="true">
                        <span><i class="at-d p"></i> حضر في المعاد</span>
                        <span><i class="at-d l"></i> اتأخر</span>
                        <span><i class="at-d a"></i> غاب</span>
                    </div>

                    <div class="at-grid" x-ref="grid"
                         x-effect="
                            const cards = [...$refs.grid.children];
                            cards.sort((a, b) => sort === 'name'
                                ? a.dataset.name.localeCompare(b.dataset.name, 'ar')
                                : (+b.dataset[sort]) - (+a.dataset[sort]));
                            cards.forEach(c => $refs.grid.appendChild(c));
                         ">
                        @foreach ($cards as $c)
                            @php
                                $s = $c['staff'];
                                $initials = mb_substr(trim($s->name), 0, 1).(str_contains(trim($s->name), ' ') ? mb_substr(trim(\Illuminate\Support\Str::afterLast(trim($s->name), ' ')), 0, 1) : '');
                                $hours = intdiv($c['overtime'], 60);
                            @endphp
                            <button type="button" class="at-card" wire:click="openStaff({{ $s->id }})"
                                    wire:key="att-card-{{ $s->id }}-{{ $this->month }}"
                                    data-name="{{ trim($s->name) }}" data-late="{{ $c['late'] }}" data-absent="{{ $c['absent'] }}" data-green="{{ $c['green'] }}"
                                    x-show="! q || $el.dataset.name.toLowerCase().includes(q.toLowerCase())"
                                    title="اضغط عشان تشوف أيام {{ trim($s->name) }}">
                                <div class="at-head">
                                    <span @class(['at-ring', 'at-none' => $c['on_time'] === null]) style="--p: {{ $c['on_time'] ?? 0 }}">
                                        <span class="at-avatar">{{ $initials }}</span>
                                    </span>
                                    <div class="at-who">
                                        <div class="at-name">{{ trim($s->name) }}</div>
                                        <div class="at-meta">
                                            {{ $s->branch?->name ?? 'من غير فرع' }}
                                            @if ($s->attendance_time)
                                                - ميعاده {{ \Illuminate\Support\Carbon::parse($s->attendance_time)->format('g:i') }}{{ \Illuminate\Support\Carbon::parse($s->attendance_time)->format('A') === 'AM' ? 'ص' : 'م' }}
                                            @endif
                                        </div>
                                    </div>
                                    <div class="at-pct">
                                        <b>{{ $c['on_time'] === null ? '—' : $c['on_time'].'%' }}</b>
                                        <small>في المعاد</small>
                                    </div>
                                </div>

                                <div class="at-nums">
                                    <div class="at-num"><b>{{ $c['present'] }}</b><small>حضر</small></div>
                                    <div @class(['at-num', 'at-warn' => $c['late'] > 0])><b>{{ $c['late'] }}</b><small>اتأخر</small></div>
                                    <div @class(['at-num', 'at-bad' => $c['absent'] > 0])><b>{{ $c['absent'] }}</b><small>غاب</small></div>
                                </div>

                                <div class="at-points">
                                    <span class="at-pt"><i style="background: var(--at-green)"></i>{{ $c['green'] }} خضرا</span>
                                    <span class="at-pt"><i style="background: var(--at-red)"></i>{{ $c['red'] }} حمرا</span>
                                    <span class="at-pt"><i style="background: var(--at-black); box-shadow: inset 0 0 0 1.5px var(--at-black-ring)"></i>{{ $c['black'] }} سودا</span>
                                    @if ($c['canceled'])
                                        <span class="at-pt at-muted">{{ $c['canceled'] }} اتلغت</span>
                                    @endif
                                </div>

                                <div class="at-strip" aria-label="أيام الشهر">
                                    @foreach ($c['strip'] as $d)
                                        @php($k = match ($d['state']) { 'green', 'present' => 'p', 'red', 'black', 'canceled' => 'l', 'absent' => 'a', default => 'n' })
                                        <i class="at-d {{ $k }}" title="{{ $d['date'] }}{{ ['p' => ' - حضر', 'l' => ' - اتأخر', 'a' => ' - غاب', 'n' => ''][$k] }}">{{ $d['day'] }}</i>
                                    @endforeach
                                </div>

                                <div class="at-foot">
                                    <span>خصم قائم <b>{{ number_format($c['deductions']) }} ج</b></span>
                                    <span>خضرا متاحة <b>{{ $c['green_available'] }}</b></span>
                                    <span>وقت زيادة <b>{{ $c['overtime'] ? ($hours ? $hours.'س ' : '').($c['overtime'] % 60 ? ($c['overtime'] % 60).'د' : '') : '—' }}</b></span>
                                </div>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        @else
            <x-filament-panels::resources.tabs />

            {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE, scopes: $this->getRenderHookScopes()) }}

            {{ $this->table }}

            {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER, scopes: $this->getRenderHookScopes()) }}
        @endif
    </div>
</x-filament-panels::page>
