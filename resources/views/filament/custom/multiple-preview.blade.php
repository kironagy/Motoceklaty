@php
    use Illuminate\Support\Str;
    use Illuminate\Support\Facades\Storage;
    use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

    $fileList = $files instanceof \Closure ? $files($record ?? null) : $files;
    $fileList = is_array($fileList) ? $fileList : [];

    $toUrl = function ($file) {
        if (!$file) return null;

        if ($file instanceof TemporaryUploadedFile) {
            return $file->temporaryUrl(); // عرض فقط
        }

        if (Str::startsWith($file, ['http://', 'https://'])) {
            return $file;
        }

        return Storage::disk('public')->url($file); // متخزن
    };

    $isTemporary = fn ($file) => $file instanceof TemporaryUploadedFile;
@endphp

@if(count($fileList))
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-2">
        @foreach($fileList as $file)
            @php
                $url = $toUrl($file);
                $isVideo = $url && Str::endsWith(Str::lower($url), ['.mp4', '.webm']);
            @endphp

            @if($url)
                <div class="rounded-lg border bg-white p-3 space-y-3">
                    <a href="{{ $url }}" target="_blank" class="block">
                        @if($isVideo)
                            <video src="{{ $url }}" controls class="rounded-lg border w-full h-48 object-cover"></video>
                        @else
                            <img src="{{ $url }}" class="rounded-lg border w-full h-48 object-cover" />
                        @endif
                    </a>

                    <div class="flex gap-2">
                        <a href="{{ $url }}" target="_blank"
                           class="inline-flex items-center justify-center gap-2 px-3 py-2 rounded-md text-white bg-primary-600 hover:bg-primary-700 text-sm">
                            فتح
                        </a>

                        {{-- التحميل شغال فقط بعد الحفظ --}}
                        @if(!$isTemporary($file))
                            <a href="{{ $url }}" download
                               class="inline-flex items-center justify-center gap-2 px-3 py-2 rounded-md text-white bg-warning-600 hover:bg-warning-700 text-sm">
                                تحميل
                            </a>
                        @else
                            <span class="text-xs text-gray-500 self-center">
                                التحميل بعد الحفظ
                            </span>
                        @endif
                    </div>
                </div>
            @endif
        @endforeach
    </div>
@endif

