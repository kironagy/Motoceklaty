<div dir="rtl" class="space-y-6">
    @if ($record->legacy_source)
        <x-filament::badge color="info">سجل قديم محفوظ</x-filament::badge>
    @endif
    <div style="border-radius:18px;padding:24px;background:linear-gradient(120deg,#102d3a,#155e75);color:white">
        <div style="font-size:12px;opacity:.75">المرشد · الحدث #{{ $record->id }}</div>
        <div style="font-size:21px;font-weight:700;margin:10px 0">{{ $record->actor_name }} • {{ $record->subjectTitle() }}</div>
        <div style="margin:10px 0">{{ $record->changeSummary() }}</div>
        <div style="opacity:.85">{{ $record->created_at->timezone('Africa/Cairo')->format('d/m/Y — H:i:s') }}</div>
    </div>

    @if(count($record->readableChanges()))
        <h3 class="font-bold text-lg">إيه اللي اتغير؟</h3>
        <div class="space-y-3">
            @foreach($record->readableChanges() as $change)
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <div class="font-bold mb-3">{{ $change['field'] }}</div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach(['before' => 'قبل العملية', 'after' => 'بعد العملية'] as $key => $label)
                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                                <div class="text-xs text-gray-500 mb-2">{{ $label }}</div>
                                <div class="text-sm whitespace-pre-wrap break-words" dir="auto">{{ \App\Support\GuideRecorder::displayValue($change[$key] ?? null, $change['field']) }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <p class="text-sm text-gray-500">{{ in_array($record->event, ['login','logout']) ? 'ده سجل دخول أو خروج، مش تعديل بيانات.' : 'السجل المحفوظ لا يحتوي على قيم قبل وبعد لهذه العملية.' }}</p>
    @endif

    <details class="rounded-xl border border-gray-200 p-4 text-sm dark:border-gray-700">
        <summary class="cursor-pointer font-bold">معلومات إضافية عن العملية</summary>
        @if ($record->legacy_source)<div>مصدر السجل القديم: {{ $record->legacy_source }} #{{ $record->legacy_id }}</div>@endif
        <div>صاحب العملية: {{ $record->actor_name }} @if($record->actor_id) (#{{ $record->actor_id }}) @endif</div>
        <div class="mt-2">عنوان الشبكة: {{ data_get($record->context, 'ip') ?: '—' }}</div>
        <div class="mt-2 break-all">المسار: <span dir="ltr">{{ data_get($record->context, 'path') ?: 'عملية تلقائية' }}</span></div>
        @if(data_get($record->context, 'page'))
            <div class="mt-2">صفحة الإجراء: {{ data_get($record->context, 'page') }}</div>
        @endif
        @if(data_get($record->context, 'response_status'))
            <div class="mt-2">نتيجة الطلب: {{ data_get($record->context, 'response_status') < 400 ? 'اكتمل طلب HTTP' : 'لم يكتمل الطلب' }}</div>
        @endif
        @if(data_get($record->context, 'actions'))
            <div class="mt-2">الإجراءات: {{ implode('، ', data_get($record->context, 'actions')) }}</div>
        @endif
        @if(data_get($record->context, 'operations'))
            <div class="mt-2">عمليات قاعدة البيانات (تشمل العمليات الجماعية):</div>
            @foreach(data_get($record->context, 'operations') as $operation)
                <div class="mt-1" dir="auto">{{ $operation }}</div>
            @endforeach
        @endif
    </details>
</div>
