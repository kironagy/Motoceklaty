<x-filament-panels::page>
    <div wire:poll.8s="refreshConnection" class="space-y-6">
        <x-filament::section icon="heroicon-o-qr-code">
            <x-slot name="heading">من دردشاتك لقائمة شغل جاهزة</x-slot>
            <x-slot name="description">اربط حسابك من واتساب ← الأجهزة المرتبطة ← ربط جهاز. افتح الكاميرا وامسح الكود الظاهر هنا.</x-slot>
            @if ($connectionError)
                <p class="text-danger-600">{{ $connectionError }}</p>
            @else
                <div class="flex flex-wrap items-center gap-4">
                    @if (!empty($connection['account']))
                        <x-filament::badge color="info">حساب واتساب المتصل: <bdi>+{{ $connection['account'] }}</bdi></x-filament::badge>
                    @endif
                    <x-filament::badge :color="($connection['status'] ?? '') === 'connected' ? 'success' : 'warning'">
                        {{ ['wrong_account' => 'الرقم مختلف • امسح الكود بنفس رقم الحساب السابق', 'connected' => 'متصل • جاري استقبال المزامنة', 'qr' => 'جاهز للمسح', 'starting' => 'جاري تجهيز الكود', 'reconnecting' => 'جاري إعادة محاولة الاتصال بواتساب', 'logged_out' => 'انتهت الجلسة • افصل الحساب ثم أعد الربط', 'error' => 'تعذر الاتصال', 'storage_error' => 'تعذر حفظ المزامنة'][''.($connection['status'] ?? '')] ?? 'غير متصل' }}
                    </x-filament::badge>
                    <x-filament::badge color="gray">{{ number_format($connection['count'] ?? 0) }} رقم مؤكد بدون تكرار</x-filament::badge>
                    @if (($connection["unresolved"] ?? 0) > 0)<x-filament::badge color="warning">{{ number_format($connection["unresolved"]) }} دردشة رقمها غير متاح حاليًا • لن تُصدّر</x-filament::badge>@endif
                    <x-filament::button wire:click="refreshConnection" color="gray" outlined>تحديث الحالة</x-filament::button>
                </div>
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">لو الأرقام تخص حسابًا قديمًا، اختار «ربط حساب واتساب آخر» من أعلى الصفحة، وامسح الكود الجديد من الهاتف المطلوب. زر «إظهار QR» يستكمل نفس الحساب الحالي.</p>
                @if (isset($connection['progress']))<p class="mt-2 text-sm">اكتمال دفعة المزامنة الحالية: {{ (int) $connection['progress'] }}٪ • لا تعني اكتمال كل سجل الهاتف.</p>@endif
                <div class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
                    <p>لأكبر سجل متاح: اختار «مزامنة موسعة لنفس الحساب»، وامسح الكود بنفس رقم واتساب الحالي. خلي الهاتف متصلًا بالإنترنت وواتساب مفتوحًا أثناء نقل السجل. الأرقام الحالية محفوظة وتُدمج بدون تكرار.</p>
                    <p>«جلب السجل الأقدم ومراجعة الأرقام» يطلب رسائل أقدم للدردشات الناقصة المتاحة، ولا ينشئ أرقامًا لم يرسلها واتساب.</p>
                    @if (isset($connection['recovery']))
                        <p>مراجعة السجل: {{ ['idle' => 'جاهزة', 'running' => 'جاري طلب دفعات أقدم', 'waiting' => 'تم إرسال الطلبات المتاحة • في انتظار ما يرسله الهاتف', 'needs_history' => 'مطلوب مزامنة موسعة لتوفير مفاتيح السجل القديم'][$connection['recovery']['status'] ?? 'idle'] ?? 'جاهزة' }}.</p>
                        <p>{{ number_format($connection['knownChats'] ?? 0) }} دردشة لها مفاتيح سجل • {{ number_format($connection['recovery']['requested'] ?? 0) }} طلب سجل أقدم • {{ number_format($connection['recovery']['failed'] ?? 0) }} طلب تعذر إرساله.</p>
                    @endif
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
                <p>التصدير يحتوي على أرقام الهاتف المؤكدة فقط في عمود واحد؛ الأسماء والمعرّفات المخفية لا تدخل الملف. لإعادة سحب السجل استخدم «مزامنة موسعة لنفس الحساب» للاحتفاظ بالأرقام الحالية ودمج النتائج الجديدة. جلستك وداتا العملاء خاصة بحسابك.</p>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
