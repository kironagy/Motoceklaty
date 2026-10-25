<x-filament-panels::page>
    <div wire:poll.8s="refreshConnection" class="space-y-6">
        <x-filament::section icon="heroicon-o-qr-code">
            <x-slot name="heading">من دردشاتك لقائمة شغل جاهزة</x-slot>
            <x-slot name="description">اربط حسابك من واتساب ← الأجهزة المرتبطة ← ربط جهاز. افتح الكاميرا وامسح الكود الظاهر هنا.</x-slot>
            @if ($connectionError)
                <p class="text-danger-600">{{ $connectionError }}</p>
            @else
                <div class="flex flex-wrap items-center gap-4">
                    <x-filament::badge :color="($connection['status'] ?? '') === 'connected' ? 'success' : 'warning'">
                        {{ ['connected' => 'متصل • جاري استقبال المزامنة', 'qr' => 'جاهز للمسح', 'starting' => 'جاري تجهيز الكود', 'reconnecting' => 'جاري إعادة محاولة الاتصال بواتساب', 'logged_out' => 'انتهت الجلسة • افصل الحساب ثم أعد الربط', 'error' => 'تعذر الاتصال', 'storage_error' => 'تعذر حفظ المزامنة'][''.($connection['status'] ?? '')] ?? 'غير متصل' }}
                    </x-filament::badge>
                    <x-filament::badge color="gray">{{ number_format($connection['count'] ?? 0) }} عميل بدون تكرار</x-filament::badge>
                    <x-filament::button wire:click="refreshConnection" color="gray" outlined>تحديث الحالة</x-filament::button>
                </div>
                @if (!empty($connection['qr']) && str_starts_with($connection['qr'], 'data:image/png;base64,'))
                    <div class="mt-6 flex justify-center"><img src="{{ $connection['qr'] }}" alt="كود ربط حساب واتساب" width="280" height="280" class="rounded-xl bg-white p-4" /></div>
                @endif
            @endif
        </x-filament::section>
        <x-filament::section icon="heroicon-o-document-arrow-down">
            <x-slot name="heading">استخراج وتقسيم الداتا</x-slot>
            <div class="space-y-3 text-sm text-gray-600 dark:text-gray-300">
                <p>حمّل ملف Excel لكل العملاء، أو اختار عدد أفراد التيم لتحميل ZIP فيه ملف Excel مستقل لكل فرد. مثال: 300 عميل على فردين = ملفين، كل ملف 150 عميل.</p>
                <p>التوزيع متوازن، وأي زيادة بتتوزع على أول الملفات. كل عميل بيظهر في ملف واحد فقط.</p>
                <p>بنستخرج أصحاب الرسائل الواردة في الدردشات الفردية اللي واتساب بيزامنها، بدون الجروبات وبدون حفظ محتوى الرسائل. استنى المزامنة والعدد يثبت قبل التحميل؛ السجل اللي واتساب ما أتاحوش مش هنقدر نستخرجه.</p>
                <p>لو الرقم غير متاح، بنحتفظ بالاسم أو اليوزرنيم المتاح. جلستك وداتا العملاء خاصة بحسابك في السيستم.</p>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
