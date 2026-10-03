<x-filament-panels::page>
    @php
        $usd = fn ($v) => '$' . number_format((float) $v, 4);
        $cents = fn ($v) => number_format((float) $v * 100, 3) . '¢';
    @endphp

    <div style="direction: rtl;" class="text-sm text-gray-600 dark:text-gray-300">
        كل طلب للذكاء الاصطناعي بيتسجل هنا بالتوكنز بتاعته وتكلفته بالسنت. المفاتيح المدفوعة بس هي اللي بتتحسب فلوس،
        والمجانية بتتسجل بتوكنزها بتكلفة صفر. التكلفة = (التوكنز الجديدة × سعر الإدخال) + (التوكنز المتخزنة × سعر الكاش)
        + (الرد والتفكير × سعر الإخراج)، كله على مليون. الأسعار من صفحة جوجل وتقدر تعدلها تحت.
    </div>

    @foreach ($this->getPaidKeys() as $key)
        <x-filament::section style="direction: rtl;">
            <x-slot name="heading">{{ $key['name'] }} @unless ($key['is_active']) <span class="text-danger-600">(موقوف)</span> @endunless</x-slot>
            <x-slot name="description">بيتحسب من {{ $key['since'] }}</x-slot>
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <div><div class="text-xs text-gray-500">الرصيد</div><div class="text-xl font-bold tabular-nums">{{ $key['credit'] !== null ? '$' . number_format($key['credit'], 2) : '—' }}</div></div>
                <div><div class="text-xs text-gray-500">المصروف</div><div class="text-xl font-bold tabular-nums text-warning-600">{{ $usd($key['spent']) }}</div></div>
                <div><div class="text-xs text-gray-500">الفاضل</div><div @class(['text-xl font-bold tabular-nums', 'text-success-600' => ($key['remaining'] ?? 1) > 5, 'text-danger-600' => ($key['remaining'] ?? 1) <= 5])>{{ $key['remaining'] !== null ? $usd($key['remaining']) : '—' }}</div></div>
                <div><div class="text-xs text-gray-500">النهاردة</div><div class="text-xl font-bold tabular-nums">{{ $usd($key['today']) }}</div></div>
                <div><div class="text-xs text-gray-500">عدد الطلبات للموديل</div><div class="text-lg tabular-nums">{{ number_format($key['calls']) }}</div></div>
                <div><div class="text-xs text-gray-500">متوسط تكلفة رد العميل</div><div class="text-lg tabular-nums">{{ $key['per_reply'] !== null ? $cents($key['per_reply']) : '—' }}</div></div>
                <div><div class="text-xs text-gray-500">ردود متوقعة بالفاضل</div><div class="text-lg tabular-nums">{{ $key['replies_left'] !== null ? number_format($key['replies_left']) : '—' }}</div></div>
            </div>
            <div class="mt-4 flex flex-wrap items-end gap-3">
                <label class="text-xs">الرصيد بالدولار
                    <input type="number" step="0.01" wire:model="credits.{{ $key['id'] }}.credit_usd" class="block w-32 rounded-lg border-gray-300 text-sm dark:bg-white/5 dark:border-white/10">
                </label>
                <label class="text-xs">بيتحسب من (لما تشحن تاني غيّره لتاريخ الشحن)
                    <input type="text" placeholder="2026-10-02 18:00" wire:model="credits.{{ $key['id'] }}.credit_since" class="block w-48 rounded-lg border-gray-300 text-sm dark:bg-white/5 dark:border-white/10">
                </label>
                <x-filament::button wire:click="saveCredits" size="sm">حفظ الرصيد</x-filament::button>
            </div>
        </x-filament::section>
    @endforeach

    <x-filament::section style="direction: rtl;" collapsible>
        <x-slot name="heading">آخر ١٤ يوم</x-slot>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-right">
                <thead class="text-gray-500"><tr>
                    <th class="py-2 px-3">اليوم</th><th class="py-2 px-3">التكلفة</th><th class="py-2 px-3">طلبات (مدفوع)</th>
                    <th class="py-2 px-3">توكنز إدخال</th><th class="py-2 px-3">منها متخزنة</th><th class="py-2 px-3">توكنز إخراج</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($this->getDaily() as $d)
                        <tr class="tabular-nums">
                            <td class="py-2 px-3">{{ $d['day'] }}</td><td class="py-2 px-3 font-medium">{{ $usd($d['cost']) }}</td>
                            <td class="py-2 px-3">{{ number_format($d['calls']) }} ({{ number_format($d['paid_calls']) }})</td>
                            <td class="py-2 px-3">{{ number_format($d['input_tokens']) }}</td><td class="py-2 px-3">{{ number_format($d['cached_tokens']) }}</td>
                            <td class="py-2 px-3">{{ number_format($d['output_tokens']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-3 px-3 text-gray-500">لسه مفيش طلبات متسجلة.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section style="direction: rtl;" collapsible>
        <x-slot name="heading">حسب الموديل والاستخدام (آخر ٣٠ يوم)</x-slot>
        <x-slot name="description">bot = ردود العملاء، tools = قراءة المستندات والصوت والصور وباقي الشغل</x-slot>
        <table class="w-full text-sm text-right">
            <thead class="text-gray-500"><tr><th class="py-2 px-3">الموديل</th><th class="py-2 px-3">الاستخدام</th><th class="py-2 px-3">طلبات (مدفوع)</th><th class="py-2 px-3">التكلفة</th></tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($this->getByModel() as $m)
                    <tr class="tabular-nums"><td class="py-2 px-3 font-mono text-xs">{{ $m['model_code'] }}</td><td class="py-2 px-3">{{ $m['source'] }}</td>
                        <td class="py-2 px-3">{{ number_format($m['calls']) }} ({{ number_format($m['paid_calls']) }})</td><td class="py-2 px-3 font-medium">{{ $usd($m['cost']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section style="direction: rtl;" collapsible>
        <x-slot name="heading">آخر ٦٠ طلب - كل سنت</x-slot>
        <div class="overflow-x-auto">
            <table class="w-full text-xs text-right">
                <thead class="text-gray-500"><tr>
                    <th class="py-1 px-2">الوقت</th><th class="py-1 px-2">المفتاح</th><th class="py-1 px-2">الموديل</th><th class="py-1 px-2">الاستخدام</th>
                    <th class="py-1 px-2">إدخال</th><th class="py-1 px-2">متخزن</th><th class="py-1 px-2">إخراج</th><th class="py-1 px-2">التكلفة</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($this->getRecent() as $r)
                        <tr class="tabular-nums">
                            <td class="py-1 px-2">{{ $r['at'] }}</td>
                            <td class="py-1 px-2">{{ $r['key'] }} @if ($r['paid'])<span class="text-warning-600">💲</span>@else<span class="text-gray-400">(مجاني)</span>@endif</td>
                            <td class="py-1 px-2 font-mono">{{ $r['model'] }}</td><td class="py-1 px-2">{{ $r['source'] }}</td>
                            <td class="py-1 px-2">{{ number_format($r['input']) }}</td><td class="py-1 px-2">{{ number_format($r['cached']) }}</td>
                            <td class="py-1 px-2">{{ number_format($r['output']) }}</td><td class="py-1 px-2 font-medium">{{ $cents($r['cost']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section style="direction: rtl;" collapsible :collapsed="true">
        <x-slot name="heading">الأسعار (دولار لكل مليون توكن)</x-slot>
        <x-slot name="description">من صفحة أسعار جوجل - لو جوجل غيّرها عدّلها هنا.</x-slot>
        <table class="w-full text-sm text-right">
            <thead class="text-gray-500"><tr><th class="py-2 px-3">الموديل</th><th class="py-2 px-3">إدخال</th><th class="py-2 px-3">متخزن (كاش)</th><th class="py-2 px-3">إخراج</th></tr></thead>
            <tbody>
                @foreach ($this->getPriceRows() as $p)
                    <tr>
                        <td class="py-2 px-3 font-mono text-xs">{{ $p['model_code'] }}</td>
                        @foreach (['input', 'cached', 'output'] as $f)
                            <td class="py-2 px-3"><input type="number" step="0.0001" wire:model="prices.{{ $p['id'] }}.{{ $f }}" class="w-28 rounded-lg border-gray-300 text-sm dark:bg-white/5 dark:border-white/10"></td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
        <x-filament::button wire:click="savePrices" size="sm" class="mt-3">حفظ الأسعار</x-filament::button>
    </x-filament::section>
</x-filament-panels::page>
