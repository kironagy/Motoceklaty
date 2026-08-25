<x-filament-panels::page>
    <div class="fi-ta-text-sm" style="direction: rtl;">
        <p>
            الأرقام دي بتتقرا من الداتابيز مباشرة (مش من اللوج). أهم رقم فيهم هو
            إجمالي الرصيد: كل رد بيتصرف منه، وأول ما يخلص البوت بيبطّل يرد خالص —
            مفيش موديل احتياطي وراه.
        </p>
        <p>
            لمراجعة أسبوعية كاملة (عناوين ميموري مكسورة، صياغات اترفضت، تحويلات):
            <code>php artisan ai:weekly-review</code>
        </p>
    </div>

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
