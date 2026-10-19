{{-- A team's daily report (DailyTaskReportResource): summary, then each task with its proof. --}}
<x-filament-panels::page>
    @php
        $r = $this->record->load(['team.leader', 'sender', 'tasks.files', 'tasks.assignee', 'tasks.creator', 'tasks.team']);
        $pct = $r->tasks_total ? (int) round($r->tasks_done / $r->tasks_total * 100) : 0;
        $byPerson = $r->tasks->groupBy(fn ($t) => $t->assignee?->name ?? '—');
    @endphp
    <style>
        .rp { --rp-card: #fff; --rp-line: #e5e7eb; --rp-soft: #f8fafc; --rp-text: #111827; --rp-muted: #6b7280; --rp-shadow: 0 12px 30px rgba(15,23,42,.08); }
        .dark .rp { --rp-card: #1C293E; --rp-line: rgba(255,255,255,.08); --rp-soft: rgba(255,255,255,.04); --rp-text: #f8fafc; --rp-muted: rgba(229,231,235,.6); --rp-shadow: 0 18px 45px rgba(0,0,0,.35); }
        .rp { display: grid; gap: 1rem; }
        .rp-card { background: var(--rp-card); border: 1px solid var(--rp-line); border-radius: 20px; padding: 1.1rem 1.2rem; box-shadow: var(--rp-shadow); color: var(--rp-text); }
        .rp-head { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; justify-content: space-between; }
        .rp-head small { color: var(--rp-muted); }
        .rp-big { font-size: 2rem; font-weight: 800; line-height: 1; }
        .rp-bar { height: .55rem; border-radius: 999px; background: var(--rp-line); overflow: hidden; margin-top: .8rem; }
        .rp-bar i { display: block; height: 100%; background: linear-gradient(90deg, #10b981, #2563eb); }
        .rp-note { margin-top: .8rem; background: var(--rp-soft); border: 1px solid var(--rp-line); border-radius: 12px; padding: .6rem .8rem; white-space: pre-line; }
        .rp-person { font-weight: 800; font-size: 1rem; margin-bottom: .6rem; }
        .rp-task { border-top: 1px dashed var(--rp-line); padding-top: .8rem; margin-top: .8rem; }
    </style>
    <div class="rp" dir="rtl">
        <div class="rp-card">
            <div class="rp-head">
                <div>
                    <div style="font-weight:800;font-size:1.1rem">{{ $r->team?->name }}</div>
                    <small>بعته {{ $r->sender?->name }} · {{ $r->created_at->format('d/m/Y h:i A') }}</small>
                </div>
                <div style="text-align:center"><div class="rp-big">{{ $r->tasks_done }}/{{ $r->tasks_total }}</div><small>مهمة اتنفذت ({{ $pct }}%)</small></div>
            </div>
            <div class="rp-bar"><i style="width: {{ $pct }}%"></i></div>
            @if (filled($r->notes))<div class="rp-note">{{ $r->notes }}</div>@endif
        </div>

        @foreach ($byPerson as $name => $tasks)
            <div class="rp-card">
                <div class="rp-person">👤 {{ $name }} — {{ $tasks->where('status', 'done')->count() }}/{{ $tasks->count() }}</div>
                @foreach ($tasks as $task)
                    <div @class(['rp-task' => ! $loop->first])>
                        <div style="font-weight:700;margin-bottom:.5rem">{{ $task->status === 'done' ? '✅' : '⏳' }} {{ $task->title }}</div>
                        @include('filament.resources.daily-tasks.task', ['task' => $task])
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
