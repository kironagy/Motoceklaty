<x-filament-panels::page>
    @php $current = $this->getCurrent(); @endphp

    <x-filament::section dir="rtl">
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
            <div>
                النسخة الشغالة:
                <span class="font-bold" dir="ltr">{{ $current['version'] }}</span>
                <span class="text-gray-500">({{ $current['source'] === 'dashboard' ? 'متعدلة من لوحة التحكم' : 'ملف السيستم' }})</span>
            </div>
            <div>
                المعتمدة:
                @if ($current['approved'] === $current['version'])
                    <x-filament::badge color="success" class="inline-flex">{{ $current['approved'] }} ✓</x-filament::badge>
                @elseif ($current['approved'])
                    <x-filament::badge color="warning" class="inline-flex">{{ $current['approved'] }} - مش هي الشغالة</x-filament::badge>
                @else
                    <x-filament::badge color="gray" class="inline-flex">مفيش</x-filament::badge>
                @endif
            </div>
        </div>
        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
            دي التعليمات الأساسية اللي البوت بيمشي عليها في كل رسالة (شخصيته، الممنوعات، إزاي يستخدم الأدوات).
            المعلومات المتغيرة زي الأسعار والمستندات والفروع مكانها جداولها مش هنا، والإرشادات الصغيرة مكانها "المعرفة التجارية".
            كل حفظ بيعمل نسخة جديدة، وتقدر ترجع لأي نسخة قديمة من الجدول تحت.
        </p>
    </x-filament::section>

    <form wire:submit="publish" class="space-y-4">
        {{ $this->form }}

        <div class="flex gap-3" dir="rtl">
            <x-filament::button type="submit" icon="heroicon-o-check">
                احفظ كنسخة جديدة
            </x-filament::button>
            <x-filament::button tag="a" color="gray" icon="heroicon-o-chat-bubble-left-ellipsis" :href="\App\Filament\Pages\ConversationSimulatorPage::getUrl()">
                جرّب في المحاكي
            </x-filament::button>
        </div>
    </form>

    {{ $this->table }}
</x-filament-panels::page>
