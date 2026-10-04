<x-filament-panels::page>
    @php($s = $this->getSummary())
    <div style="direction: rtl;" class="space-y-6">
        <div class="flex gap-2">
            @foreach ([1 => 'آخر 24 ساعة', 7 => 'آخر 7 أيام', 30 => 'آخر 30 يوم'] as $d => $label)
                <x-filament::button size="sm" :color="$days === $d ? 'primary' : 'gray'" wire:click="$set('days', {{ $d }})">{{ $label }}</x-filament::button>
            @endforeach
        </div>

        <x-filament::section>
            <x-slot name="heading">الملخص</x-slot>
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <div><div class="text-xs text-gray-500">عدد الردود</div><div class="text-2xl font-bold tabular-nums">{{ number_format($s['replies']) }}</div></div>
                <div><div class="text-xs text-gray-500">ردود طلعت سليمة من أول مرة</div><div @class(['text-2xl font-bold tabular-nums', 'text-success-600' => ($s['clean_percent'] ?? 0) >= 90, 'text-warning-600' => ($s['clean_percent'] ?? 0) < 90])>{{ $s['clean_percent'] !== null ? $s['clean_percent'].'%' : '—' }}</div><div class="text-xs text-gray-500 tabular-nums">{{ number_format($s['clean']) }} رد</div></div>
                <div><div class="text-xs text-gray-500">ردود احتياطية (البوت ما عرفش يرد)</div><div @class(['text-2xl font-bold tabular-nums', 'text-danger-600' => $s['fallbacks'] > 0])>{{ number_format($s['fallbacks']) }}</div></div>
                <div><div class="text-xs text-gray-500">أعطال</div><div @class(['text-2xl font-bold tabular-nums', 'text-danger-600' => $s['errors'] > 0])>{{ number_format($s['errors']) }}</div></div>
                <div><div class="text-xs text-gray-500">المراجعة رجّعت رد قبل ما يتبعت</div><div class="text-2xl font-bold tabular-nums">{{ number_format($s['review_redo']) }}</div></div>
                <div><div class="text-xs text-gray-500">متوسط وقت الرد</div><div class="text-2xl font-bold tabular-nums">{{ $s['avg_seconds'] !== null ? $s['avg_seconds'].' ث' : '—' }}</div></div>
                <div><div class="text-xs text-gray-500">90% من الردود أسرع من</div><div class="text-2xl font-bold tabular-nums">{{ $s['p90_seconds'] !== null ? $s['p90_seconds'].' ث' : '—' }}</div></div>
            </div>
            <p class="mt-3 text-xs text-gray-500">"سليمة من أول مرة" = محدش من الفحوصات اعترض عليها. الرد اللي اترفض بيتصلح قبل ما يوصل للعميل، فالرقم ده بيقيس قد إيه البوت بيغلط، مش قد إيه العميل شاف غلط.</p>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">أكتر الأخطاء اللي اتمسكت</x-slot>
            @forelse ($s['codes'] as $code => $n)
                <div class="flex justify-between border-b py-1 text-sm"><span>{{ \App\Filament\Pages\ReplyQualityPage::codeLabel($code) }}</span><span class="tabular-nums font-bold">{{ $n }}</span></div>
            @empty
                <p class="text-sm text-gray-500">مفيش أخطاء في الفترة دي.</p>
            @endforelse
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">آخر الردود اللي احتاجت تصليح</x-slot>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-gray-500"><th class="p-2 text-right">الوقت</th><th class="p-2 text-right">المحادثة</th><th class="p-2 text-right">العميل قال</th><th class="p-2 text-right">اتبعتله</th><th class="p-2 text-right">اتمسك عليه</th></tr></thead>
                    <tbody>
                    @forelse ($this->getProblemTurns() as $row)
                        <tr class="border-t align-top">
                            <td class="p-2 tabular-nums whitespace-nowrap"><a class="text-primary-600 underline" href="{{ \App\Filament\Resources\AiTraceResource::getUrl('view', ['record' => $row['id']]) }}">{{ $row['at'] }}</a></td>
                            <td class="p-2 tabular-nums">{{ $row['conversation_id'] }}</td>
                            <td class="p-2">{{ $row['customer'] }}</td>
                            <td class="p-2">{{ $row['reply'] }} @if ($row['status'] === 'fallback')<span class="text-danger-600">(رد احتياطي)</span>@endif</td>
                            <td class="p-2">{{ collect(explode('، ', $row['codes']))->filter()->map(fn ($c) => \App\Filament\Pages\ReplyQualityPage::codeLabel($c))->implode('، ') }}<div class="text-xs text-gray-500">{{ $row['problems'] }}</div></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-2 text-gray-500">مفيش.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
