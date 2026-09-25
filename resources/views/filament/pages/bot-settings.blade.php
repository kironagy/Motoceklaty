<x-filament-panels::page>
    @php $status = $this->getStatus(); @endphp

    <div dir="rtl" class="grid gap-4 md:grid-cols-4">
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">حالة البوت</div>
            <div class="mt-1 flex items-center gap-2 text-lg font-bold">
                <span @class([
                    'inline-block h-2.5 w-2.5 rounded-full',
                    'bg-success-500' => $status['enabled'],
                    'bg-danger-500' => ! $status['enabled'],
                ])></span>
                {{ $status['enabled'] ? 'شغال وبيرد' : 'متوقف' }}
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">الموديل</div>
            <div class="mt-1 font-mono text-sm font-semibold" dir="ltr">{{ $status['model'] ?: '—' }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">التعليمات</div>
            <div class="mt-1 text-lg font-bold" dir="ltr">{{ $status['instructions_version'] }}</div>
            <div class="text-xs text-gray-500">
                {{ $status['instructions_source'] === 'dashboard' ? 'متعدلة من لوحة التحكم' : 'من ملف السيستم' }}
                @if ($status['approved_version'] && $status['approved_version'] !== $status['instructions_version'])
                    · <span class="text-warning-600">المعتمدة {{ $status['approved_version'] }}</span>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">قيم متعدلة من هنا</div>
            <div class="mt-1 text-lg font-bold">{{ $status['overrides'] }}</div>
            <div class="text-xs text-gray-500">الباقي ماشي بقيم ملف .env</div>
        </x-filament::section>
    </div>

    <form wire:submit="save" dir="rtl" class="space-y-4">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit" icon="heroicon-o-check">
                حفظ الإعدادات
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
