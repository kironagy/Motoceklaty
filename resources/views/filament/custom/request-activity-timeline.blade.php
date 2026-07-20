@php
    $record = $getRecord();

    $ignoreUntil = $record?->created_at?->copy()->addSeconds(10);

    $activities = $record
        ? \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', get_class($record))
            ->where('subject_id', $record->id)
            ->where('event', '!=', 'created')
            ->when($ignoreUntil, fn ($q) => $q->where('created_at', '>', $ignoreUntil))
            ->where(function ($q) {
                $q->whereNotNull('properties->attributes')
                  ->orWhereNotNull('properties->old');
            })
            ->with('causer')
            ->latest()
            ->limit(20)
            ->get()
        : collect();

    $labels = [
        'status' => 'حالة الطلب',
        'checks_report' => 'السبب',
        'staff_id' => 'الموظف',
        'installment_type' => 'نوع النظام',
        'machine_id' => 'المكنة',
        'machine_installment_price' => 'سعر المكنة',
        'deposit' => 'المقدم',
        'applicant_name' => 'اسم العميل',
        'applicant_phone' => 'رقم الهاتف',
        'notes' => 'الملاحظات',
    ];

    $statusLabels = [
        'new' => 'انتظار',
        'new_request' => 'طلب جديد',
        'pending' => 'تحت الاستعلام',
        'work_check' => 'استعلام عمل',
        'approved' => 'موافقة',
        'rejected' => 'رفض',
        'paused' => 'متوقف',
        'transferred' => 'محول',
        'delivered' => 'استلم المكنة',
        'canceled' => 'الطلب ملغي',
    ];

    $formatValue = function ($field, $value) use ($statusLabels) {
        if ($value === null || $value === '') {
            return '-';
        }

        if ($field === 'status') {
            return $statusLabels[$value] ?? $value;
        }

        if ($field === 'staff_id') {
            return \App\Models\Staff::find($value)?->name ?? $value;
        }

        if ($field === 'machine_id') {
            return \App\Models\Machine::find($value)?->name ?? $value;
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return $value;
    };
@endphp

<div class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900" dir="rtl">
    <div class="mb-5">
        <h3 class="text-lg font-black text-gray-900 dark:text-white">سجل تعديلات الطلب</h3>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            التعديلات التي تمت بعد إنشاء الطلب فقط
        </p>
    </div>

    <div class="space-y-4">
        @forelse($activities as $activity)
            @php
                $changes = $activity->properties['attributes'] ?? [];
                $old = $activity->properties['old'] ?? [];

                $changes = collect($changes)
                    ->only(array_keys($labels))
                    ->filter(fn ($newValue, $field) => ($old[$field] ?? null) != $newValue);
            @endphp

            @if($changes->isNotEmpty())
                <div class="relative rounded-2xl border border-orange-100 bg-orange-50/50 p-4 dark:border-orange-900/40 dark:bg-orange-950/20">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="font-bold text-gray-900 dark:text-white">
                            {{ $activity->causer?->name ?? 'غير معروف' }}
                        </div>

                        <div class="rounded-full bg-white px-3 py-1 text-xs font-bold text-orange-600 shadow-sm dark:bg-gray-800">
                            {{ $activity->created_at->format('d/m/Y - h:i A') }}
                        </div>
                    </div>

                    <div class="mt-3 space-y-2">
                        @foreach($changes as $field => $newValue)
                            <div class="rounded-xl bg-white p-3 text-sm shadow-sm dark:bg-gray-800">
                                <div class="mb-2 font-black text-gray-800 dark:text-gray-100">
                                    {{ $labels[$field] ?? $field }}
                                </div>

                                <div class="grid gap-2 md:grid-cols-2">
                                    <div class="rounded-lg bg-red-50 p-2 text-red-700 dark:bg-red-950/30 dark:text-red-300">
                                        <span class="font-bold">قبل:</span>
                                        {{ $formatValue($field, $old[$field] ?? null) }}
                                    </div>

                                    <div class="rounded-lg bg-green-50 p-2 text-green-700 dark:bg-green-950/30 dark:text-green-300">
                                        <span class="font-bold">بعد:</span>
                                        {{ $formatValue($field, $newValue) }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        @empty
            <div class="rounded-2xl border border-dashed border-gray-300 p-6 text-center text-gray-500 dark:border-gray-700">
                لسه مفيش تعديلات متسجلة على الطلب
            </div>
        @endforelse
    </div>
</div>
