<div class="space-y-2 max-h-96 overflow-y-auto" dir="rtl">
    @forelse ($messages as $message)
        <div class="p-2 rounded-lg {{ $message->direction === 'incoming' ? 'bg-gray-100 dark:bg-gray-800' : 'bg-primary-100 dark:bg-primary-900 ms-8' }}">
            <div class="text-xs opacity-60">{{ $message->direction === 'incoming' ? 'العميل' : 'الرد' }} — {{ $message->created_at?->format('Y-m-d H:i') }}</div>
            <div>{{ $message->message }}</div>
        </div>
    @empty
        <p>مفيش رسائل.</p>
    @endforelse
</div>
