{{-- Daily tasks: a to-do board, one card per member with every task in detail, or the table. --}}
@php
    use App\Filament\Resources\DailyTaskResource as R;
    use App\Models\DailyTask;
    use App\Models\DailyTaskFile;

    $heads = [
        DailyTask::TODO => ['🕒', 'wait'],
        DailyTask::DOING => ['⚙️', 'doing'],
        DailyTask::DONE => ['✅', 'ok'],
    ];
    $duration = function ($a, $b) {
        if (! $a || ! $b) return null;
        $m = (int) $a->diffInMinutes($b);
        return $m < 60 ? "{$m} دقيقة" : intdiv($m, 60).' ساعة'.($m % 60 ? ' و'.($m % 60).' دقيقة' : '');
    };
@endphp

<x-filament-panels::page @class(['fi-resource-list-records-page', 'fi-resource-'.str_replace('/', '-', $this->getResource()::getSlug())])>
    <style>
        .tb { --tb-line: #e5e7eb; --tb-soft: #f8fafc; --tb-card: #fff; --tb-text: #111827; --tb-muted: #6b7280; color: var(--tb-text); }
        .dark .tb { --tb-line: rgba(255,255,255,.08); --tb-soft: rgba(255,255,255,.03); --tb-card: rgba(255,255,255,.05); --tb-text: #f8fafc; --tb-muted: rgba(229,231,235,.6); }
        .tb-bar { display: flex; flex-wrap: wrap; align-items: end; gap: .75rem; justify-content: space-between; }
        .tb-modes { display: inline-flex; padding: .25rem; border-radius: 12px; background: var(--tb-soft); border: 1px solid var(--tb-line); }
        .tb-modes button { padding: .45rem .9rem; border-radius: 9px; font-size: .85rem; font-weight: 700; color: var(--tb-muted); }
        .tb-modes button.on { background: var(--tb-card); color: var(--tb-text); box-shadow: 0 1px 3px rgba(0,0,0,.12); }
        .tb-filters { display: flex; flex-wrap: wrap; gap: .5rem; }
        .tb-filters label { display: grid; gap: .2rem; font-size: .72rem; font-weight: 700; color: var(--tb-muted); }
        .tb-filters select, .tb-filters input { min-width: 9rem; border-radius: 10px; border: 1px solid var(--tb-line); background: var(--tb-card); color: var(--tb-text); font-size: .85rem; padding: .4rem .6rem; }
        .tb-board { display: grid; gap: 1rem; grid-template-columns: repeat(3, minmax(0, 1fr)); align-items: start; }
        @media (max-width: 1024px) { .tb-board { grid-template-columns: 1fr; } }
        .tb-col { border-radius: 16px; background: var(--tb-soft); border: 1px solid var(--tb-line); padding: .75rem; display: grid; gap: .6rem; }
        .tb-col-h { display: flex; justify-content: space-between; align-items: center; font-weight: 800; padding: .1rem .2rem .3rem; border-bottom: 3px solid var(--c); }
        .tb-col-h span.n { min-width: 1.8rem; text-align: center; padding: .1rem .5rem; border-radius: 999px; background: var(--c); color: #fff; font-size: .8rem; }
        .wait { --c: #f59e0b; } .doing { --c: #3b82f6; } .ok { --c: #10b981; }
        .tb-card { background: var(--tb-card); border: 1px solid var(--tb-line); border-inline-start: 4px solid var(--c); border-radius: 12px; padding: .7rem .8rem; display: grid; gap: .4rem; }
        .tb-title { font-weight: 800; font-size: .95rem; line-height: 1.4; }
        .tb-desc { font-size: .8rem; color: var(--tb-muted); white-space: pre-line; }
        .tb-meta { display: flex; flex-wrap: wrap; gap: .35rem; font-size: .72rem; color: var(--tb-muted); }
        .tb-meta span { padding: .15rem .5rem; border-radius: 999px; background: var(--tb-soft); border: 1px solid var(--tb-line); }
        .tb-meta b { color: var(--tb-text); }
        .tb-late { color: #b91c1c !important; background: rgba(239,68,68,.1) !important; border-color: rgba(239,68,68,.35) !important; }
        .tb-acts { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; padding-top: .3rem; border-top: 1px dashed var(--tb-line); }
        .tb-acts .grow { flex: 1; }
        .tb-card[draggable="true"] { cursor: grab; }
        .tb-card.dragging { opacity: .4; transform: scale(.98); }
        .tb-col { transition: background .15s, box-shadow .15s, opacity .15s; }
        .tb-board.is-dragging .tb-col:not(.can-drop) { opacity: .55; }
        .tb-col.can-drop { box-shadow: inset 0 0 0 2px color-mix(in srgb, var(--c) 45%, transparent); }
        .tb-col.drop-over { background: color-mix(in srgb, var(--c) 14%, transparent); box-shadow: inset 0 0 0 2px var(--c); }
        .tb-hint { font-size: .75rem; color: var(--tb-muted); margin-top: -.5rem; }
        .tb-empty { text-align: center; font-size: .8rem; color: var(--tb-muted); padding: 1.2rem .5rem; border: 1px dashed var(--tb-line); border-radius: 12px; }
        .tb-people { display: grid; gap: 1rem; }
        .tb-person { background: var(--tb-card); border: 1px solid var(--tb-line); border-radius: 16px; overflow: hidden; }
        .tb-person > summary { list-style: none; cursor: pointer; display: flex; flex-wrap: wrap; align-items: center; gap: .75rem 1rem; padding: .9rem 1rem; }
        .tb-person > summary::-webkit-details-marker { display: none; }
        .tb-avatar { width: 2.4rem; height: 2.4rem; border-radius: 999px; display: grid; place-items: center; font-weight: 800; background: rgba(59,130,246,.15); color: #2563eb; }
        .tb-name { font-weight: 800; font-size: 1rem; flex: 1; min-width: 8rem; }
        .tb-counts { display: flex; gap: .4rem; font-size: .75rem; font-weight: 700; }
        .tb-counts span { padding: .2rem .55rem; border-radius: 999px; color: var(--c); background: color-mix(in srgb, var(--c) 14%, transparent); }
        .tb-progress { width: 9rem; height: .5rem; border-radius: 999px; background: var(--tb-soft); border: 1px solid var(--tb-line); overflow: hidden; }
        .tb-progress i { display: block; height: 100%; background: #10b981; }
        .tb-pct { font-size: .8rem; font-weight: 800; min-width: 2.5rem; }
        .tb-list { border-top: 1px solid var(--tb-line); padding: .8rem 1rem 1rem; display: grid; gap: .7rem; }
        .tb-row { border: 1px solid var(--tb-line); border-inline-start: 4px solid var(--c); border-radius: 12px; padding: .7rem .8rem; display: grid; gap: .45rem; background: var(--tb-soft); }
        .tb-row-h { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
        .tb-row-h .tb-title { flex: 1; min-width: 10rem; }
        .tb-chip { font-size: .72rem; font-weight: 800; padding: .15rem .55rem; border-radius: 999px; color: var(--c); background: color-mix(in srgb, var(--c) 14%, transparent); }
        .tb-note { font-size: .82rem; white-space: pre-line; padding: .5rem .65rem; border-radius: 10px; background: var(--tb-card); border: 1px solid var(--tb-line); }
        .tb-note small { display: block; font-size: .7rem; font-weight: 800; color: var(--tb-muted); margin-bottom: .15rem; }
        .tb-files { display: flex; flex-wrap: wrap; gap: .4rem; }
        .tb-files a { display: flex; align-items: center; gap: .3rem; font-size: .72rem; text-decoration: none; color: var(--tb-text); border: 1px solid var(--tb-line); background: var(--tb-card); border-radius: 8px; padding: .2rem; padding-inline-end: .5rem; max-width: 14rem; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
        .tb-files a:hover { border-color: #2563eb; }
        .tb-files img { width: 2.6rem; height: 2.6rem; object-fit: cover; border-radius: 6px; }
        .tb-files .ic { width: 2.6rem; height: 2.6rem; display: grid; place-items: center; font-size: 1.2rem; }
    </style>

    <div class="tb flex flex-col gap-y-6" dir="rtl">
        <div class="tb-bar">
            <div class="tb-modes">
                @foreach (static::MODES as $key => $label)
                    <button type="button" wire:click="setMode('{{ $key }}')" @class(['on' => $mode === $key])>{{ $label }}</button>
                @endforeach
            </div>

            @if ($mode !== 'table')
                <div class="tb-filters">
                    @if (count($teams = $this->teamOptions()) > 1)
                        <label>التيم
                            <select wire:model.live="teamId">
                                <option value="">كل التيمات</option>
                                @foreach ($teams as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                        </label>
                    @endif
                    @if (count($people = $this->memberOptions()) > 1)
                        <label>الموظف
                            <select wire:model.live="memberId">
                                <option value="">الكل</option>
                                @foreach ($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                        </label>
                    @endif
                    <label>من <input type="date" wire:model.live="from"></label>
                    <label>إلى <input type="date" wire:model.live="to"></label>
                </div>
            @endif
        </div>

        @if ($mode === 'table')
            <x-filament-panels::resources.tabs />
            {{ $this->table }}

        @elseif ($mode === 'board')
            {{-- Drag a card to another column: to «جاري» starts it, to «تم» opens the proof form, back to «مطلوب» asks first. --}}
            <div class="tb-hint">✋ اسحب الكارت لعمود تاني عشان تغيّر حالته.</div>
            <div class="tb-board"
                 x-data="{
                     drag: null, moves: [], over: null,
                     actions: { {{ DailyTask::DOING }}: 'startTask', {{ DailyTask::DONE }}: 'finishTask', {{ DailyTask::TODO }}: 'reopenTask' },
                     drop(status) {
                         if (this.drag && this.moves.includes(status)) $wire.mountAction(this.actions[status], { task: this.drag });
                         this.drag = null; this.over = null; this.moves = [];
                     },
                 }"
                 x-bind:class="{ 'is-dragging': drag }">
                @foreach ($columns as $status => $tasks)
                    <div class="tb-col {{ $heads[$status][1] }}"
                         x-bind:class="{ 'can-drop': drag && moves.includes('{{ $status }}'), 'drop-over': over === '{{ $status }}' }"
                         x-on:dragover="if (moves.includes('{{ $status }}')) { $event.preventDefault(); over = '{{ $status }}' }"
                         x-on:dragleave="if (! $el.contains($event.relatedTarget)) over = null"
                         x-on:drop.prevent="drop('{{ $status }}')">
                        <div class="tb-col-h">
                            <span>{{ $heads[$status][0] }} {{ DailyTask::STATUSES[$status] }}</span>
                            <span class="n">{{ $tasks->count() }}</span>
                        </div>

                        @forelse ($tasks as $task)
                            @php
                                $moves = array_keys(array_filter([
                                    DailyTask::DOING => R::canStart($task),
                                    DailyTask::DONE => R::canFinish($task),
                                    DailyTask::TODO => R::canReopen($task),
                                ]));
                            @endphp
                            <div class="tb-card" wire:key="task-{{ $task->id }}"
                                 @if ($moves)
                                     draggable="true"
                                     x-on:dragstart="drag = {{ $task->id }}; moves = @js($moves); $event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('text/plain', '{{ $task->id }}')"
                                     x-on:dragend="drag = null; over = null; moves = []"
                                     x-bind:class="{ 'dragging': drag === {{ $task->id }} }"
                                 @endif>
                                <div class="tb-title">{{ $task->title }}</div>
                                @if (filled($task->description))
                                    <div class="tb-desc">{{ \Illuminate\Support\Str::limit($task->description, 140) }}</div>
                                @endif
                                <div class="tb-meta">
                                    <span>👤 <b>{{ $task->assignee?->name }}</b></span>
                                    <span @class(['tb-late' => $task->isLate()])>📅 {{ $task->task_date?->format('d/m') }}{{ $task->isLate() ? ' · متأخرة' : '' }}</span>
                                    @if ($task->attachments_count)<span>📎 {{ $task->attachments_count }}</span>@endif
                                    @if ($task->proofs_count)<span>🧾 {{ $task->proofs_count }} إثبات</span>@endif
                                    @if ($task->started_at && $status === DailyTask::DOING)<span>بدأ {{ $task->started_at->format('h:i A') }}</span>@endif
                                    @if ($task->done_at)<span>خلص {{ $task->done_at->format('d/m h:i A') }}</span>@endif
                                    @if ($task->report_id)<span>📤 في التقرير</span>@endif
                                </div>
                                <div class="tb-acts">
                                    @if (R::canStart($task)){{ ($this->startTaskAction)(['task' => $task->id]) }}@endif
                                    @if (R::canFinish($task)){{ ($this->finishTaskAction)(['task' => $task->id]) }}@endif
                                    {{ ($this->openTaskAction)(['task' => $task->id]) }}
                                    @if (R::canReopen($task)){{ ($this->reopenTaskAction)(['task' => $task->id]) }}@endif
                                    <span class="grow"></span>
                                    @if (R::canEdit($task))
                                        <x-filament::icon-button icon="heroicon-o-pencil-square" color="gray" tag="a" :href="R::getUrl('edit', ['record' => $task])" label="تعديل" />
                                    @endif
                                    @if (R::canDelete($task)){{ ($this->deleteTaskAction)(['task' => $task->id]) }}@endif
                                </div>
                            </div>
                        @empty
                            <div class="tb-empty">مفيش مهام هنا</div>
                        @endforelse
                    </div>
                @endforeach
            </div>

        @else
            <div class="tb-people">
                @forelse ($members as $row)
                    @php
                        $tasks = $row['tasks'];
                        $done = $tasks->where('status', DailyTask::DONE)->count();
                        $pct = $tasks->count() ? (int) round($done * 100 / $tasks->count()) : 0;
                    @endphp
                    <details class="tb-person" wire:key="person-{{ $row['staff']->id }}" @if ($tasks->isNotEmpty()) open @endif>
                        <summary>
                            <span class="tb-avatar">{{ mb_substr($row['staff']->name, 0, 1) }}</span>
                            <span class="tb-name">{{ $row['staff']->name }}</span>
                            <span class="tb-counts">
                                @foreach ($heads as $status => [$icon, $cls])
                                    <span class="{{ $cls }}">{{ DailyTask::STATUSES[$status] }}: {{ $tasks->where('status', $status)->count() }}</span>
                                @endforeach
                            </span>
                            <span class="tb-progress"><i style="width: {{ $pct }}%"></i></span>
                            <span class="tb-pct">{{ $pct }}%</span>
                        </summary>

                        <div class="tb-list">
                            @forelse ($tasks as $task)
                                @php
                                    $proofs = $task->files->where('kind', DailyTaskFile::PROOF);
                                    $attachments = $task->files->where('kind', DailyTaskFile::ATTACHMENT);
                                @endphp
                                <div class="tb-row {{ $heads[$task->status][1] ?? '' }}" wire:key="row-{{ $task->id }}">
                                    <div class="tb-row-h">
                                        <span class="tb-chip">{{ DailyTask::STATUSES[$task->status] ?? $task->status }}</span>
                                        <span class="tb-title">{{ $task->title }}</span>
                                        {{ ($this->openTaskAction)(['task' => $task->id]) }}
                                    </div>
                                    <div class="tb-meta">
                                        <span @class(['tb-late' => $task->isLate()])>📅 {{ $task->task_date?->format('d/m/Y') }}{{ $task->isLate() ? ' · متأخرة' : '' }}</span>
                                        <span>اتضافت {{ $task->created_at?->format('d/m h:i A') }} من <b>{{ $task->creator?->name ?? '—' }}</b></span>
                                        @if ($task->started_at)<span>بدأ {{ $task->started_at->format('d/m h:i A') }}</span>@endif
                                        @if ($task->done_at)<span>خلص {{ $task->done_at->format('d/m h:i A') }}</span>@endif
                                        @if ($d = $duration($task->started_at, $task->done_at))<span>⏱ <b>{{ $d }}</b></span>@endif
                                        @if ($task->report_id)<span>📤 في التقرير</span>@endif
                                    </div>
                                    @if (filled($task->description))
                                        <div class="tb-note"><small>المطلوب</small>{{ $task->description }}</div>
                                    @endif
                                    @if (filled($task->done_note))
                                        <div class="tb-note"><small>فيدباك الموظف</small>{{ $task->done_note }}</div>
                                    @endif
                                    @foreach ([['الإثبات', $proofs], ['ملفات المهمة', $attachments]] as [$label, $files])
                                        @if ($files->isNotEmpty())
                                            <div>
                                                <div class="tb-meta" style="margin-bottom: .25rem"><b>{{ $label }} ({{ $files->count() }})</b></div>
                                                <div class="tb-files">
                                                    @foreach ($files as $f)
                                                        <a href="{{ $f->url() }}" target="_blank" title="{{ $f->name() }}">
                                                            @if ($f->isImage())<img src="{{ $f->url() }}" alt="">@else<span class="ic">📄</span>@endif
                                                            {{ $f->name() }}
                                                        </a>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            @empty
                                <div class="tb-empty">ملوش مهام في الأيام دي</div>
                            @endforelse
                        </div>
                    </details>
                @empty
                    <div class="tb-empty">مفيش موظفين في التيم</div>
                @endforelse
            </div>
        @endif
    </div>

    {{-- The page renders the action modals inside the table only; the board and the members view have none. --}}
    @if ($mode !== 'table')
        <x-filament-actions::modals />
    @endif
</x-filament-panels::page>
