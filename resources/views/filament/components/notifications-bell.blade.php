@php
    $count = \App\Models\Notification::where('user_id', auth()->id())
        ->where('is_read', false)
        ->count();
@endphp

<a href="/admin/notifications" class="relative flex items-center mx-3">

    <x-heroicon-o-bell class="w-6 h-6 text-gray-300" />

    @if($count > 0)
        <span style="background:#ECBF24!important;border-radius:25px;padding:3px;font-size:12px;height:13px;width:auto"
            class="absolute -top-2 -right-3
                  
                   flex items-center justify-center">
            {{ $count }}
        </span>
    @endif

</a>

