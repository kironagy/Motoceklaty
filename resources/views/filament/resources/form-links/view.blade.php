<x-filament-panels::page>
    @include('filament.resources.form-links.partials.styles')
    @php
        $d = $this->getDetails();
        $link = $this->record;
        $percent = $link->status === 'submitted' ? 100 : $d['progress']['percent'];
        $ago = fn ($t) => \App\Support\EgyptianTime::ago($t);
        $events = array_values(array_filter([
            ['done' => true, 'title' => 'الرابط اتبعت', 'note' => ($link->created_by_type === 'bot' ? 'من البوت' : 'من '.($link->createdByStaff?->name ?? 'موظف')), 'at' => $link->created_at],
            ['done' => (bool) $link->first_opened_at, 'title' => 'فتح الرابط', 'note' => $link->first_opened_at ? null : 'لسه مفتحهوش', 'at' => $link->first_opened_at],
            $link->followups_sent ? ['done' => true, 'title' => 'اتبعتله متابعة', 'note' => $link->followups_sent > 1 ? $link->followups_sent.' مرات' : null, 'at' => $link->last_followup_at] : null,
            ['done' => (bool) $link->last_activity_at, 'title' => 'آخر حركة في الفورم', 'note' => $link->last_activity_at ? null : '—', 'at' => $link->last_activity_at],
            ['done' => $link->status === 'submitted', 'title' => 'بعت الطلب', 'note' => $link->status === 'submitted' ? ($link->receipt_status === 'failed' ? 'رسالة الاستلام موصلتش' : 'وصلته رسالة الاستلام') : null, 'at' => $link->submitted_at, 'warn' => $link->receipt_status === 'failed'],
        ]));
    @endphp
    <style>
        .lv { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 16px; align-items: start; }
        @media (max-width: 1100px) { .lv { grid-template-columns: 1fr; } }
        .lv-card { background: var(--panel); border: 1px solid var(--line); border-radius: 16px; }
        .lv-head { display: flex; align-items: center; gap: 16px; padding: 18px 20px; margin-bottom: 16px; }
        .lv-head h2 { font-size: 1.3rem; font-weight: 800; margin: 0; }
        .lv-head .lv-phone { color: var(--muted); direction: ltr; unicode-bidi: isolate; font-size: .9rem; }
        .lv-head .lv-pills { display: flex; gap: 6px; margin-inline-start: auto; flex-wrap: wrap; }
        .lv-note { margin: 0 20px 16px; padding: 12px 14px; border-radius: 12px; background: var(--brand-soft); font-size: .9rem; }
        .lv-note.bad { background: var(--bad-soft); color: var(--bad); font-weight: 700; }

        .lv-step { padding: 4px 0 8px; }
        .lv-step + .lv-step { border-top: 1px solid var(--line); }
        .lv-step h3 { font-size: .95rem; font-weight: 800; margin: 0; padding: 14px 20px 6px; display: flex; align-items: center; gap: 10px; }
        .lv-step h3 i { font-style: normal; width: 24px; height: 24px; border-radius: 50%; background: var(--panel-2); display: inline-flex; align-items: center; justify-content: center; font-size: .75rem; }
        .lv-qa { display: grid; grid-template-columns: minmax(150px, 34%) 1fr; gap: 12px; padding: 9px 20px; font-size: .9rem; }
        .lv-qa.hidden { opacity: .45; }
        .lv-q { color: var(--muted); }
        .lv-q small { display: block; font-size: .72rem; color: var(--ok); }
        .lv-q .req { color: var(--bad); }
        .lv-a { font-weight: 600; word-break: break-word; white-space: pre-line; }
        .lv-a.empty { color: var(--muted); font-weight: 400; }
        .lv-a.missing { color: var(--wait); font-weight: 700; }
        .lv-err { display: block; color: var(--bad); font-size: .75rem; font-weight: 700; margin-top: 2px; }
        .lv-files { display: flex; flex-wrap: wrap; gap: 10px; }
        .lv-file { width: 168px; border: 1px solid var(--line); border-radius: 12px; overflow: hidden; background: var(--panel-2); text-decoration: none; color: inherit; }
        .lv-file img { display: block; width: 100%; height: 112px; object-fit: cover; }
        .lv-file .lv-doc { height: 112px; display: flex; align-items: center; justify-content: center; font-size: 2rem; }
        .lv-file span { display: block; padding: 6px 8px; font-size: .75rem; font-weight: 700; line-height: 1.5; }
        .lv-file.ok span { color: var(--ok); }
        .lv-file.warning { border-color: var(--bad); }
        .lv-file.warning span { color: var(--bad); background: var(--bad-soft); }

        .lv-side { display: flex; flex-direction: column; gap: 16px; position: sticky; top: 80px; }
        .lv-ring { display: flex; align-items: center; gap: 16px; padding: 18px 20px; }
        .lv-ring svg { width: 72px; height: 72px; transform: rotate(-90deg); flex: none; }
        .lv-ring circle { fill: none; stroke-width: 8; }
        .lv-ring .bg { stroke: var(--panel-2); }
        .lv-ring .fg { stroke: var(--wait); stroke-linecap: round; transition: stroke-dashoffset .4s; }
        .lv-ring.done .fg { stroke: var(--ok); }
        .lv-ring b { display: block; font-size: 1.5rem; font-weight: 800; font-variant-numeric: tabular-nums; }
        .lv-ring p { margin: 0; font-size: .82rem; color: var(--muted); line-height: 1.6; }

        .lv-time { list-style: none; margin: 0; padding: 16px 20px; }
        .lv-time li { position: relative; padding: 0 22px 16px 0; }
        .lv-time li::before { content: ''; position: absolute; right: 4px; top: 6px; width: 10px; height: 10px; border-radius: 50%; border: 2px solid var(--line); background: var(--panel); }
        .lv-time li::after { content: ''; position: absolute; right: 8px; top: 18px; bottom: -2px; width: 2px; background: var(--line); }
        .lv-time li:last-child { padding-bottom: 0; }
        .lv-time li:last-child::after { display: none; }
        .lv-time li.done::before { background: var(--ok); border-color: var(--ok); }
        .lv-time li.warn::before { background: var(--bad); border-color: var(--bad); }
        .lv-time li.warn small { color: var(--bad); }
        .lv-time strong { display: block; font-size: .88rem; }
        .lv-time small { color: var(--muted); font-size: .78rem; }
        .lv-time li:not(.done) strong { color: var(--muted); font-weight: 600; }

        .lv-box { padding: 16px 20px; display: flex; flex-direction: column; gap: 8px; font-size: .85rem; }
        .lv-box h4 { margin: 0; font-size: .82rem; color: var(--muted); font-weight: 700; }
        .lv-url { display: flex; gap: 6px; }
        .lv-url input { flex: 1; min-width: 0; border: 1px solid var(--line); border-radius: 9px; background: var(--panel-2); color: var(--ink); padding: 7px 9px; font-size: .78rem; direction: ltr; }
        .lv-url button { border: 1px solid var(--line); background: var(--panel); color: var(--ink); border-radius: 9px; padding: 0 12px; cursor: pointer; font-size: .8rem; }
        .lv-req { display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; border-radius: 12px; background: var(--ok-soft); color: var(--ok); font-weight: 800; text-decoration: none; }
    </style>

    <div class="lk">
        <div class="lv-card lv-head">
            <div>
                <h2>{{ $this->applicantName() ?: 'لسه مكتبش اسمه' }}</h2>
                <span class="lv-phone">{{ $this->phone() }}</span>
            </div>
            <div class="lv-pills">
                @if ($link->purpose === 'fix')<span class="lk-pill fix">تعديل بيانات</span>@endif
                <span class="lk-pill {{ $link->status }}">{{ \App\Models\FormLink::STATUS_LABELS[$link->status] ?? $link->status }}</span>
            </div>
            <x-filament-actions::actions :actions="$this->getCachedHeaderActions()" />
        </div>

        <div class="lv">
            <div class="lv-card">
                @if ($link->purpose === 'fix' && $link->staff_note)
                    <p class="lv-note" style="margin-top:16px"><b>المطلوب تعديله:</b> {{ $link->staff_note }}</p>
                @endif
                @foreach ($d['blocking'] as $m)
                    <p class="lv-note bad" style="margin-top:16px">التقديم واقف: {{ $m }}</p>
                @endforeach

                @foreach ($d['sections'] as $n => $section)
                    <section class="lv-step">
                        <h3><i>{{ $n + 1 }}</i>{{ $section['title'] }}</h3>
                        @foreach ($section['rows'] as $row)
                            <div class="lv-qa {{ $row['shown'] ? '' : 'hidden' }}">
                                <div class="lv-q">
                                    {{ $row['label'] }}@if ($row['required'])<span class="req"> *</span>@endif
                                    @if ($row['binding'])<small>بيتسجل في: {{ $row['binding'] }}</small>@endif
                                    @unless ($row['shown'])<small style="color:var(--muted)">مش ظاهر له بإجاباته</small>@endunless
                                </div>
                                @if ($row['is_document'])
                                    <div class="lv-files">
                                        @forelse ($row['files'] as $file)
                                            <a class="lv-file {{ $file['status'] }}" href="{{ $file['url'] }}" target="_blank">
                                                @if ($file['is_image'])<img src="{{ $file['url'] }}" alt="" loading="lazy">@else<div class="lv-doc">📄</div>@endif
                                                <span>
                                                    @switch($file['status'])
                                                        @case('ok') ✓ الـ AI: مطابق @break
                                                        @case('warning') ⚠ {{ $file['message'] }} @break
                                                        @case('checking') بيتفحص... @break
                                                        @default اترفع من غير فحص
                                                    @endswitch
                                                </span>
                                            </a>
                                        @empty
                                            <span class="lv-a {{ $row['shown'] && $row['required'] && $link->status !== 'submitted' ? 'missing' : 'empty' }}">لسه مترفعش</span>
                                        @endforelse
                                    </div>
                                @else
                                    <div class="lv-a {{ $row['value'] === null ? ($row['shown'] && $row['required'] && $link->status !== 'submitted' ? 'missing' : 'empty') : '' }}">{{ $row['value'] ?? ($row['shown'] && $row['required'] ? 'لسه مكتبهوش' : '—') }}@if ($row['error'] && $row['value'] !== null)<small class="lv-err">{{ $row['error'] }}</small>@endif</div>
                                @endif
                            </div>
                        @endforeach
                    </section>
                @endforeach
            </div>

            <aside class="lv-side">
                <div class="lv-card lv-ring {{ $percent >= 100 ? 'done' : '' }}">
                    @php($c = 2 * M_PI * 30)
                    <svg viewBox="0 0 72 72" aria-hidden="true"><circle class="bg" cx="36" cy="36" r="30"/><circle class="fg" cx="36" cy="36" r="30" stroke-dasharray="{{ $c }}" stroke-dashoffset="{{ $c * (1 - $percent / 100) }}"/></svg>
                    <div>
                        <b>{{ $percent }}٪</b>
                        <p>
                            @if ($link->status === 'submitted') بعت الطلب كامل
                            @elseif ($d['progress']['missing']) فاضل {{ count($d['progress']['missing']) }} من المطلوب
                            @else كل المطلوب اتملى ومستني يدوس إرسال
                            @endif
                        </p>
                    </div>
                </div>

                @if ($link->installment_request_id)
                    <a class="lv-req" href="{{ \App\Filament\Resources\DeliveryResource::getUrl('edit', ['record' => $link->installment_request_id]) }}">
                        <span>افتح الطلب #{{ $link->installment_request_id }}</span><span>‹</span>
                    </a>
                @endif

                <div class="lv-card">
                    <ol class="lv-time">
                        @foreach ($events as $e)
                            <li class="{{ $e['done'] ? 'done' : '' }} {{ ($e['warn'] ?? false) ? 'warn' : '' }}">
                                <strong>{{ $e['title'] }}</strong>
                                <small>{{ $e['at'] ? $ago($e['at']) : '' }}@if ($e['at'] && $e['note']) - @endif{{ $e['note'] }}</small>
                            </li>
                        @endforeach
                    </ol>
                </div>

                <div class="lv-card lv-box" x-data="{ copied: false }">
                    <h4>الرابط</h4>
                    <div class="lv-url">
                        <input readonly value="{{ $link->url() }}" x-ref="url" @focus="$el.select()">
                        <button type="button" @click="navigator.clipboard.writeText($refs.url.value); copied = true; setTimeout(() => copied = false, 1500)" x-text="copied ? 'اتنسخ' : 'نسخ'"></button>
                    </div>
                    <small style="color: var(--muted)">
                        @if ($link->isOpen()) شغال لحد {{ $link->expires_at?->format('Y-m-d') }} @else مبقاش شغال @endif
                    </small>
                </div>
            </aside>
        </div>
    </div>
</x-filament-panels::page>
