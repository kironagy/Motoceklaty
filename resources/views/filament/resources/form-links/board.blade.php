<x-filament-panels::page>
    @include('filament.resources.form-links.partials.styles')
    <style>
        .lk-funnel { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0; background: var(--panel); border: 1px solid var(--line); border-radius: 16px; overflow: hidden; }
        .lk-stage { position: relative; text-align: start; padding: 16px 20px 14px; border: 0; background: transparent; color: var(--ink); cursor: pointer; border-inline-start: 1px solid var(--line); }
        .lk-stage:first-child { border-inline-start: 0; }
        .lk-stage:hover { background: var(--panel-2); }
        .lk-stage.on { background: var(--brand-soft); }
        .lk-stage.on::after { content: ''; position: absolute; inset-inline: 0; bottom: 0; height: 3px; background: var(--brand); }
        .lk-stage b { display: block; font-size: 1.9rem; line-height: 1.1; font-weight: 800; font-variant-numeric: tabular-nums; }
        .lk-stage span { font-weight: 700; font-size: .9rem; }
        .lk-stage small { display: block; color: var(--muted); font-size: .75rem; }
        .lk-stage em { position: absolute; top: 16px; inset-inline-end: 18px; font-style: normal; font-size: .78rem; color: var(--muted); }
        .lk-stage:not(:first-child)::before { content: ''; position: absolute; top: 50%; inset-inline-start: -7px; width: 12px; height: 12px; background: var(--panel); border-top: 1px solid var(--line); border-inline-start: 1px solid var(--line); transform: translateY(-50%) rotate(-45deg); z-index: 1; }
        [dir=rtl] .lk-stage:not(:first-child)::before { transform: translateY(-50%) rotate(45deg); }

        .lk-tools { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; margin: 16px 0 10px; }
        .lk-chips { display: flex; gap: 6px; flex-wrap: wrap; }
        .lk-chip { border: 1px solid var(--line); background: var(--panel); color: var(--ink); border-radius: 999px; padding: 6px 12px; font-size: .82rem; cursor: pointer; }
        .lk-chip.on { border-color: var(--brand); color: var(--brand-ink); background: var(--brand-soft); font-weight: 700; }
        .lk-chip i { font-style: normal; color: var(--muted); margin-inline-start: 4px; }
        .lk-search { border: 1px solid var(--line); background: var(--panel); color: var(--ink); border-radius: 10px; padding: 8px 12px; min-width: 260px; font-size: .9rem; }
        .lk-search:focus { outline: none; border-color: var(--brand); }

        .lk-list { background: var(--panel); border: 1px solid var(--line); border-radius: 16px; overflow: hidden; }
        .lk-row { display: grid; grid-template-columns: minmax(180px, 1.2fr) minmax(220px, 1.6fr) minmax(150px, .9fr) auto 24px; gap: 18px; align-items: center; padding: 14px 18px; border-top: 1px solid var(--line); color: inherit; text-decoration: none; }
        .lk-row:first-child { border-top: 0; }
        .lk-row:hover { background: var(--panel-2); }
        .lk-who strong { display: block; font-size: .95rem; }
        .lk-who span { color: var(--muted); font-size: .82rem; direction: ltr; unicode-bidi: isolate; }
        .lk-progress { display: flex; flex-direction: column; gap: 6px; }
        .lk-progress-top { display: flex; justify-content: space-between; font-size: .8rem; }
        .lk-progress-top b { font-variant-numeric: tabular-nums; }
        .lk-left { font-size: .78rem; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .lk-left .warn { color: var(--bad); font-weight: 700; }
        .lk-when { font-size: .8rem; color: var(--muted); line-height: 1.6; }
        .lk-when b { color: var(--ink); font-weight: 600; }
        .lk-end { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }
        .lk-req { font-size: .8rem; font-weight: 700; color: var(--brand-ink); }
        .lk-go { color: var(--muted); }
        .lk-empty { padding: 48px 20px; text-align: center; color: var(--muted); }
        .lk-empty strong { display: block; color: var(--ink); font-size: 1rem; margin-bottom: 4px; }
        @media (max-width: 1000px) { .lk-funnel { grid-template-columns: repeat(2, 1fr); } .lk-row { grid-template-columns: 1fr; gap: 8px; } .lk-end { align-items: flex-start; flex-direction: row; } .lk-go { display: none; } }
    </style>

    <div class="lk" wire:poll.30s>
        <div class="lk-funnel" role="tablist" aria-label="مراحل الرابط">
            @foreach ($this->funnel() as $i => $s)
                @php($first = $this->funnel()[0]['count'] ?: 1)
                <button type="button" role="tab" class="lk-stage {{ $stage === $s['key'] ? 'on' : '' }}" wire:click="$set('stage', '{{ $s['key'] }}')" aria-selected="{{ $stage === $s['key'] ? 'true' : 'false' }}">
                    <b>{{ number_format($s['count']) }}</b>
                    <span>{{ $s['label'] }}</span>
                    <small>{{ $s['hint'] }}</small>
                    @if ($i > 0)<em>{{ round(100 * $s['count'] / $first) }}٪</em>@endif
                </button>
            @endforeach
        </div>

        <div class="lk-tools">
            <div class="lk-chips">
                @foreach ($this->extraStages() as $key => $s)
                    <button type="button" class="lk-chip {{ $stage === $key ? 'on' : '' }}" wire:click="$set('stage', '{{ $stage === $key ? 'all' : $key }}')">
                        {{ $s['label'] }}<i>{{ $s['count'] }}</i>
                    </button>
                @endforeach
            </div>
            <input type="search" class="lk-search" wire:model.live.debounce.400ms="search" placeholder="دوّر برقم الموبايل أو رقم الطلب">
        </div>

        @php($rows = $this->rows())
        <div class="lk-list">
            @forelse ($rows as $link)
                @php($ui = $link->ui)
                <a class="lk-row" href="{{ $this->viewUrl($link) }}" wire:key="link-{{ $link->id }}">
                    <div class="lk-who">
                        <strong>{{ $ui['name'] ?: 'لسه مكتبش اسمه' }}</strong>
                        <span>{{ $ui['phone'] }}</span>
                    </div>

                    <div class="lk-progress">
                        <div class="lk-progress-top">
                            <span>{{ $link->version?->form?->name }}</span>
                            <b>{{ $ui['percent'] }}٪</b>
                        </div>
                        <div class="lk-bar {{ $ui['percent'] >= 100 ? 'done' : '' }}"><span style="width: {{ $ui['percent'] }}%"></span></div>
                        <div class="lk-left">
                            @if ($ui['warnings'])<span class="warn">⚠ {{ $ui['warnings'] }} صورة محتاجة مراجعة</span>&nbsp; @endif
                            @if ($link->status === 'submitted')
                                اتقدم {{ \App\Support\EgyptianTime::ago($link->submitted_at) }}
                            @elseif ($ui['missing'])
                                فاضل: {{ implode('، ', $ui['missing']) }}@if ($ui['missing_more']) و{{ $ui['missing_more'] }} كمان@endif
                            @else
                                كل المطلوب اتملى
                            @endif
                        </div>
                    </div>

                    <div class="lk-when">
                        اتبعت <b>{{ \App\Support\EgyptianTime::ago($link->created_at) }}</b><br>
                        @if ($link->last_activity_at) آخر حركة {{ \App\Support\EgyptianTime::ago($link->last_activity_at) }} @else لسه مفتحش @endif
                        @if ($link->followups_sent)<br>اتبعتله {{ $link->followups_sent }} متابعة @endif
                    </div>

                    <div class="lk-end">
                        <span class="lk-pill {{ $link->status }}">{{ \App\Models\FormLink::STATUS_LABELS[$link->status] ?? $link->status }}</span>
                        @if ($link->purpose === 'fix')<span class="lk-pill fix">تعديل بيانات</span>@endif
                        @if ($link->installment_request_id)<span class="lk-req">طلب #{{ $link->installment_request_id }}</span>@endif
                    </div>
                    <span class="lk-go">‹</span>
                </a>
            @empty
                <div class="lk-empty">
                    <strong>مفيش روابط هنا</strong>
                    الروابط بتظهر هنا أول ما البوت أو موظف يبعت رابط تقديم لعميل.
                </div>
            @endforelse
        </div>

        <div style="margin-top: 12px">{{ $rows->links() }}</div>
    </div>
</x-filament-panels::page>
