<div class="overflow-x-auto">
    @if ($transfers->isEmpty())
        <p class="text-sm text-gray-500 py-6 text-center">مفيش تحويلات لسه.</p>
    @else
        <table class="w-full text-sm text-right">
            <thead>
                <tr class="border-b border-gray-200 dark:border-white/10 text-gray-500">
                    <th class="py-2 px-2">التاريخ</th>
                    <th class="py-2 px-2">الموديل</th>
                    <th class="py-2 px-2">المكنة</th>
                    <th class="py-2 px-2">من</th>
                    <th class="py-2 px-2">إلى</th>
                    <th class="py-2 px-2">حوّلها</th>
                    <th class="py-2 px-2">ملاحظة</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($transfers as $t)
                    <tr class="border-b border-gray-100 dark:border-white/5">
                        <td class="py-2 px-2 whitespace-nowrap">{{ $t->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="py-2 px-2">{{ $t->machine?->name ?? '-' }}</td>
                        <td class="py-2 px-2">شاسيه: {{ $t->item?->chassis_number ?: '-' }} • {{ $t->item?->color }} • #{{ $t->inventory_item_id }}</td>
                        <td class="py-2 px-2">{{ $t->from?->name ?? '-' }}</td>
                        <td class="py-2 px-2 font-semibold">{{ $t->to?->name ?? '-' }}</td>
                        <td class="py-2 px-2">{{ $t->staff?->name ?? '-' }}</td>
                        <td class="py-2 px-2">{{ $t->note }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
