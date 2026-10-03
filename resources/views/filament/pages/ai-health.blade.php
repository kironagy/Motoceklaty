<x-filament-panels::page>
    <div class="fi-ta-text-sm" style="direction: rtl;">
        <p>
            الأرقام دي بتتقرا من الداتابيز مباشرة (مش من اللوج). البوت بيرد بالمفتاح المدفوع بس،
            والمفاتيح المجانية مبتشتغلش غير لو المدفوع خلص خالص (اتقفل أو جوجل رفضته).
        </p>
    </div>

    @php
        $usd = fn ($v) => '$' . number_format((float) $v, 2);
        $cents = fn ($v) => number_format((float) $v * 100, 2) . ' سنت';
    @endphp

    @foreach ($this->getMoney() as $key)
        <x-filament::section style="direction: rtl;">
            <x-slot name="heading">الفلوس — {{ $key['name'] }} @unless ($key['is_active']) <span class="text-danger-600">(موقوف)</span> @endunless</x-slot>
            <x-slot name="description">
                من {{ $key['since'] }}. التفاصيل طلب بطلب في
                <a href="{{ \App\Filament\Pages\AiCosts::getUrl() }}" class="text-primary-600 underline">تكلفة الذكاء الاصطناعي</a>
                (وهناك تعدّل الرصيد لما تشحن).
            </x-slot>
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <div><div class="text-xs text-gray-500">الرصيد اللي شحنته</div><div class="text-xl font-bold tabular-nums">{{ $key['credit'] !== null ? $usd($key['credit']) : '—' }}</div></div>
                <div><div class="text-xs text-gray-500">اتصرف لحد دلوقتي</div><div class="text-xl font-bold tabular-nums text-warning-600">{{ $usd($key['spent']) }}</div><div class="text-xs text-gray-500 tabular-nums">{{ $cents($key['spent']) }}</div></div>
                <div><div class="text-xs text-gray-500">الفاضل</div><div @class(['text-xl font-bold tabular-nums', 'text-success-600' => ($key['remaining'] ?? 1) > 5, 'text-danger-600' => ($key['remaining'] ?? 1) <= 5])>{{ $key['remaining'] !== null ? $usd($key['remaining']) : '—' }}</div></div>
                <div><div class="text-xs text-gray-500">يكفي حوالي</div><div class="text-xl font-bold tabular-nums">{{ $key['days_left'] !== null ? number_format($key['days_left']) . ' يوم' : '—' }}</div><div class="text-xs text-gray-500">بمعدل الصرف الحالي</div></div>
                <div><div class="text-xs text-gray-500">النهاردة</div><div class="text-lg tabular-nums">{{ $cents($key['today']) }}</div></div>
                <div><div class="text-xs text-gray-500">الشهر ده</div><div class="text-lg tabular-nums">{{ $usd($key['month']) }}</div></div>
                <div><div class="text-xs text-gray-500">متوسط اليوم</div><div class="text-lg tabular-nums">{{ $cents($key['per_day']) }}</div></div>
                <div><div class="text-xs text-gray-500">متوسط رد العميل</div><div class="text-lg tabular-nums">{{ $key['per_reply'] !== null ? $cents($key['per_reply']) : '—' }}</div></div>
                <div><div class="text-xs text-gray-500">عدد الطلبات</div><div class="text-lg tabular-nums">{{ number_format($key['calls']) }}</div></div>
                <div><div class="text-xs text-gray-500">ردود متوقعة بالفاضل</div><div class="text-lg tabular-nums">{{ $key['replies_left'] !== null ? number_format($key['replies_left']) : '—' }}</div></div>
                <div><div class="text-xs text-gray-500">الكاش وفّرلك</div><div class="text-lg tabular-nums text-success-600">{{ $cents($key['saved']) }}</div></div>
                <div><div class="text-xs text-gray-500">نسبة التوكنز من الكاش</div><div class="text-lg tabular-nums">{{ $key['cached_percent'] !== null ? $key['cached_percent'] . '%' : '—' }}</div></div>
            </div>
        </x-filament::section>
    @endforeach

    <x-filament::section collapsible :collapsed="false" style="direction: rtl;">
        <x-slot name="heading">الرصيد لكل مفتاح</x-slot>

        <x-slot name="description">
            الجدول ده بيعرض الموديل اللي البوت بيرد بيه بس
            (<code>{{ implode(' + ', \App\Filament\Widgets\AiHealthOverview::modelCodesInUse()) }}</code>).
            الرصيد بيتجمّع من كل المفاتيح الشغالة: لما مفتاح يخلص حصته، النظام
            بيكمّل على المفتاح اللي بعده في نفس الرسالة من غير ما العميل يحس،
            والذاكرة محفوظة في الداتابيز مش في المفتاح — فالتبديل مبيضيّعش السياق.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-right">
                <thead class="border-b border-gray-200 dark:border-white/10">
                    <tr class="text-gray-500 dark:text-gray-400">
                        <th class="py-2 px-3 font-medium">المفتاح</th>
                        <th class="py-2 px-3 font-medium">المستهلك النهاردة</th>
                        <th class="py-2 px-3 font-medium">الفاضل</th>
                        <th class="py-2 px-3 font-medium">الحالة</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($this->getKeyQuotaRows() as $key)
                        @foreach ($key['models'] as $model)
                            <tr @class(['opacity-50' => ! $key['is_active'] || ! $model['is_active']])>
                                <td class="py-2 px-3 font-medium">
                                    {{ $key['name'] }}
                                    @if (count($key['models']) > 1)
                                        <span class="block font-mono text-xs text-gray-500">{{ $model['model_code'] }}</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 tabular-nums">
                                    {{ number_format($model['used']) }} / {{ number_format($model['limit']) }}
                                    <span class="text-xs text-gray-500">({{ $model['percent'] }}%)</span>
                                </td>
                                <td class="py-2 px-3 tabular-nums">{{ number_format($model['remaining']) }}</td>
                                <td class="py-2 px-3">
                                    @if (! $key['is_active'] || ! $model['is_active'])
                                        <span class="text-danger-600 dark:text-danger-400">موقوف</span>
                                    @elseif ($model['cooldown_until'])
                                        <span class="text-warning-600 dark:text-warning-400">
                                            مستني {{ $model['cooldown_until'] }}
                                        </span>
                                    @elseif (! $model['is_available'])
                                        <span class="text-warning-600 dark:text-warning-400">خلص حصته</span>
                                    @else
                                        <span class="text-success-600 dark:text-success-400">متاح</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    @empty
                        <tr>
                            <td class="py-4 px-3 text-gray-500" colspan="4">
                                مفيش مفتاح Gemini متركّب عليه الموديل ده. ضيف مفتاح من صفحة Gemini API Keys.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
