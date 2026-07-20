@php
    $record = $getRecord();

    $finalUrl = null;
    if (isset($url)) {
        $finalUrl = is_callable($url) ? $url($record) : $url;
    }
@endphp

@if ($record && $finalUrl)
    <div class="w-full flex flex-col items-center justify-center mt-2 space-y-2">
        <img src="{{ $finalUrl }}" class="rounded-lg shadow-md border border-gray-700 max-w-full" />

        <a href="{{ $finalUrl }}"
           download
           target="_blank"
           class="inline-flex items-center justify-center gap-2 px-3 py-1.5
                  bg-primary-600 text-white text-sm font-medium rounded-md
                  shadow-sm hover:bg-primary-700 transition-all duration-200">
            <x-heroicon-o-arrow-down-tray class="w-4 h-4" />
            {{ $label ?? 'تحميل الصورة' }}
        </a>
    </div>
@endif

