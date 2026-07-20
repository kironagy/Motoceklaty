@php
    use Illuminate\Support\Facades\Storage;

    $record = $getRecord();
    $list = $files instanceof \Closure ? $files($record) : $files;
    $list = is_array($list) ? $list : [];

    $urls = collect($list)
        ->filter()
        ->map(fn ($path) => Storage::disk('public')->url($path))
        ->values()
        ->toArray();
@endphp

@if($record && count($urls))
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-2">
        @foreach($urls as $i => $url)
            <div class="w-full flex flex-col items-center justify-center space-y-2">
                <img src="{{ $url }}" class="rounded-lg shadow-md border border-gray-700 max-w-full" />

                <a href="{{ $url }}"
                   download
                   target="_blank"
                   class="inline-flex items-center justify-center gap-2 px-3 py-1.5
                          bg-primary-600 text-white text-sm font-medium rounded-md
                          shadow-sm hover:bg-primary-700 transition-all duration-200">
                    <x-heroicon-o-arrow-down-tray class="w-4 h-4" />
                    {{ ($label ?? 'تحميل') . ' ' . ($i + 1) }}
                </a>
            </div>
        @endforeach
    </div>
@endif
