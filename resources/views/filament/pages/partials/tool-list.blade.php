<div dir="rtl" class="space-y-3 text-sm">
    @foreach ($tools as $tool)
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <code class="font-mono font-semibold" dir="ltr">{{ $tool['name'] }}</code>
            <p class="mt-1 text-gray-600 dark:text-gray-400" dir="auto">{{ \Illuminate\Support\Str::limit($tool['description'], 400) }}</p>
        </div>
    @endforeach
</div>
