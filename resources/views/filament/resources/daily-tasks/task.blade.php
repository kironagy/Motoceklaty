{{-- One daily task: details, the leader's files to download, and the member's proof. --}}
@php
    $attachments = $task->files->where('kind', \App\Models\DailyTaskFile::ATTACHMENT);
    $proofs = $task->files->where('kind', \App\Models\DailyTaskFile::PROOF);
    $done = $task->status === \App\Models\DailyTask::DONE;
@endphp
<style>
    .dt { --dt-line: #e5e7eb; --dt-soft: #f8fafc; --dt-text: #111827; --dt-muted: #6b7280; }
    .dark .dt { --dt-line: rgba(255,255,255,.08); --dt-soft: rgba(255,255,255,.04); --dt-text: #f8fafc; --dt-muted: rgba(229,231,235,.6); }
    .dt { display: grid; gap: 1rem; color: var(--dt-text); }
    .dt-meta { display: flex; flex-wrap: wrap; gap: .5rem; font-size: .8rem; }
    .dt-chip { padding: .3rem .7rem; border-radius: 999px; border: 1px solid var(--dt-line); background: var(--dt-soft); color: var(--dt-muted); }
    .dt-chip b { color: var(--dt-text); }
    .dt-chip.ok { color: #047857; background: rgba(16,185,129,.12); border-color: rgba(16,185,129,.4); }
    .dt-chip.wait { color: #b45309; background: rgba(245,158,11,.12); border-color: rgba(245,158,11,.4); }
    .dt-chip.doing { color: #1d4ed8; background: rgba(59,130,246,.12); border-color: rgba(59,130,246,.4); }
    .dt-box { border: 1px solid var(--dt-line); border-radius: 14px; padding: .8rem .9rem; background: var(--dt-soft); white-space: pre-line; font-size: .9rem; }
    .dt h4 { font-size: .8rem; font-weight: 800; color: var(--dt-muted); margin-bottom: .4rem; }
    .dt-files { display: grid; grid-template-columns: repeat(auto-fill, minmax(9rem, 1fr)); gap: .5rem; }
    .dt-file { display: flex; flex-direction: column; gap: .35rem; border: 1px solid var(--dt-line); border-radius: 12px; padding: .45rem; text-decoration: none; color: var(--dt-text); font-size: .78rem; word-break: break-all; }
    .dt-file:hover { border-color: #2563eb; }
    .dt-file img { width: 100%; height: 6rem; object-fit: cover; border-radius: 8px; }
    .dt-file .ic { height: 6rem; display: grid; place-items: center; font-size: 2rem; background: var(--dt-soft); border-radius: 8px; }
</style>
<div class="dt" dir="rtl">
    <div class="dt-meta">
        <span class="dt-chip {{ $done ? 'ok' : ($task->status === \App\Models\DailyTask::DOING ? 'doing' : 'wait') }}">{{ \App\Models\DailyTask::STATUSES[$task->status] ?? $task->status }}</span>
        <span class="dt-chip">لـ <b>{{ $task->assignee?->name }}</b></span>
        <span class="dt-chip">من <b>{{ $task->creator?->name ?? '—' }}</b></span>
        <span class="dt-chip">{{ $task->team?->name }}</span>
        <span class="dt-chip">{{ $task->task_date?->format('d/m/Y') }}</span>
        @if ($task->started_at)<span class="dt-chip">بدأ {{ $task->started_at->format('d/m h:i A') }}</span>@endif
        @if ($task->done_at)<span class="dt-chip ok">اتنفذت {{ $task->done_at->format('d/m h:i A') }}</span>@endif
    </div>

    @if (filled($task->description))
        <div><h4>التفاصيل</h4><div class="dt-box">{{ $task->description }}</div></div>
    @endif

    @foreach ([['ملفات المهمة', $attachments, 'لسه مفيش ملفات.'], ['الإثبات', $proofs, $done ? 'مفيش إثبات.' : 'لسه متنفذتش.']] as [$title, $files, $empty])
        <div>
            <h4>{{ $title }} ({{ $files->count() }})</h4>
            @if ($files->isEmpty())
                <div class="dt-box" style="color: var(--dt-muted)">{{ $empty }}</div>
            @else
                <div class="dt-files">
                    @foreach ($files as $f)
                        <a class="dt-file" href="{{ $f->url() }}" target="_blank" download="{{ $f->name() }}">
                            @if ($f->isImage())<img src="{{ $f->url() }}" alt="">@else<span class="ic">📄</span>@endif
                            <span>⬇️ {{ $f->name() }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    @endforeach

    @if (filled($task->done_note))
        <div><h4>فيدباك الموظف</h4><div class="dt-box">{{ $task->done_note }}</div></div>
    @endif
</div>
