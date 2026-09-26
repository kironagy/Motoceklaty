@php
    use App\Domain\Teaching\EditableEntities;
    $kinds = ['lesson' => 'درس', 'instruction' => 'تعديل في التعليمات', 'data_update' => 'تعديل بيانات', 'data_create' => 'إضافة بيانات', 'setting' => 'إعداد'];
    $statuses = ['proposed' => 'مستني موافقتك', 'applied' => 'اتطبق', 'rejected' => 'اتلغى', 'reverted' => 'اترجع فيه', 'invalid' => 'مينفعش', 'failed_check' => 'فشل في الاختبار'];
    $json = fn ($v) => is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $result = (array) $session->result;
@endphp
<div class="sim-teach">
    <div class="sim-teach-owner">👤 {{ $session->owner_text }}</div>

    @if ($session->status === 'thinking')
        <div>المدرّب لسه بيشتغل…</div>
    @endif

    @if ($session->understanding)
        <div><b>فهمي:</b> {{ $session->understanding }}</div>
    @endif

    @if ($session->question)
        <div class="mt-1"><b>سؤال:</b> {{ $session->question }} <span class="text-xs opacity-70">(رد عليه بـ /علّم أو صحّح الرد تاني)</span></div>
    @endif

    @foreach ($session->changes as $change)
        @php [$before, $after] = $change->diff(); @endphp
        <div class="sim-card">
            <div class="flex flex-wrap items-center gap-2">
                <span class="sim-badge">{{ $kinds[$change->kind] ?? $change->kind }}@if (! in_array($change->kind, ['lesson', 'instruction'])) · {{ EditableEntities::label($change->target_type) }} {{ $change->target_id ? '#'.$change->target_id : '' }}@endif</span>
                <span @class(['sim-badge', 'sim-ok' => $change->status === 'applied', 'sim-bad' => in_array($change->status, ['invalid', 'failed_check'])])>{{ $statuses[$change->status] ?? $change->status }}</span>
            </div>
            <div class="mt-1">{{ $change->summary }}</div>
            @if ($change->error)
                <div class="sim-bad text-xs mt-1">{{ $change->error }}</div>
            @endif

            <details class="mt-1">
                <summary class="text-xs cursor-pointer">قبل ← بعد</summary>
                @if ($change->kind === 'instruction')
                    <pre>- {{ $after['find'] ?? '' }}</pre>
                    <pre>+ {{ $after['replace'] ?? '' }}</pre>
                @else
                    <pre>قبل: {{ $before === null ? '—' : $json($before) }}</pre>
                    <pre>بعد: {{ $json($change->kind === 'lesson' ? ($after['fields'] ?? $after) : $after) }}</pre>
                @endif
            </details>

            <div class="mt-1 flex gap-2">
                @if (in_array($change->status, ['proposed', 'failed_check']))
                    <x-filament::button size="xs" color="success" wire:click="approveChange({{ $change->id }})" wire:loading.attr="disabled">{{ $change->status === 'failed_check' ? 'طبّقه برضه' : 'موافق' }}</x-filament::button>
                    <x-filament::button size="xs" color="gray" wire:click="rejectChange({{ $change->id }})">لا</x-filament::button>
                @elseif ($change->status === 'applied')
                    <x-filament::button size="xs" color="gray" wire:click="revertChange({{ $change->id }})" wire:confirm="ترجع في التغيير ده؟">ارجع فيه</x-filament::button>
                @endif
            </div>
        </div>
    @endforeach

    @if (! empty($result['document']))
        <div class="sim-card">
            <b>قريت المستند تاني بالإعدادات الجديدة:</b> {{ $result['document']['detected_type'] ?? '—' }}
            <pre>{{ $json($result['document']['fields'] ?? []) }}</pre>
            @if (! empty($result['document']['issues']))
                <div class="sim-bad text-xs">مشاكل: {{ collect($result['document']['issues'])->pluck('code')->implode('، ') }}</div>
            @else
                <div class="sim-ok text-xs">مفيش مشاكل</div>
            @endif
        </div>
    @endif

    @foreach ($result['checks'] ?? [] as $check)
        <div class="sim-card">
            <div class="flex items-center gap-2">
                <span class="{{ $check['pass'] ? 'sim-ok' : 'sim-bad' }}">{{ $check['pass'] ? '✓' : '✗' }}</span>
                <b class="text-xs">{{ $check['label'] }}</b>
            </div>
            <div class="mt-1" style="white-space: pre-wrap">{{ $check['reply'] }}</div>
            @if (! $check['pass'])
                <div class="sim-bad text-xs mt-1">{{ $check['reason'] }}</div>
            @endif
        </div>
    @endforeach
</div>
