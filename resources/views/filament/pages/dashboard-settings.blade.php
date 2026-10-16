<x-filament-panels::page>
    <form wire:submit="save" dir="rtl" class="space-y-4">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit" icon="heroicon-o-check">
                حفظ الإعدادات
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
