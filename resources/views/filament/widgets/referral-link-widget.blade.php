<div>
@php
    $link = $this->getReferralLink();
@endphp

@if ($link)
    <x-filament::section>
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h3 class="text-base font-semibold text-white">🔗 لينك الإحالة الخاص بك</h3>
                <p class="text-sm text-gray-400 mt-1">
                    انسخ هذا الرابط وشاركه لتسجيل الطلبات باسمك 👇
                </p>
            </div>

            <div class="flex items-center gap-2 w-full sm:w-auto">
                <input id="referralLink" type="text"
                       value="{{ $link }}"
                       readonly
                       class="w-full sm:w-96 rounded-md bg-gray-800 border border-gray-600 text-gray-100 px-3 py-2 text-sm text-center">
                <button
                    onclick="navigator.clipboard.writeText(document.getElementById('referralLink').value);
                             this.innerText='تم النسخ ✅';
                             setTimeout(()=>this.innerText='نسخ',1500);"
                    class="px-3 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md transition">
                    نسخ
                </button>
            </div>
        </div>
    </x-filament::section>
@endif
</div>