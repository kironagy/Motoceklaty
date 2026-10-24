<x-filament-panels::page>
    <x-filament::section icon="heroicon-o-cube">
        <x-slot name="heading">{{ $record->name }}</x-slot>
        <x-slot name="description">مخزون الموديل في المعارض المسموح لك بها • عدّل القطع هنا، والبيع من زر «بيع» في قائمة المخزون.</x-slot>
        <div class="flex flex-wrap items-center gap-4">
            <x-filament::badge color="success" size="lg">{{ $this->getAvailableCount() }} قطعة متاحة</x-filament::badge>
            <x-filament::badge color="gray">{{ count($data['warehouses'] ?? []) }} معارض</x-filament::badge>
            <x-filament::button tag="a" color="gray" outlined icon="heroicon-o-arrow-right" :href="\App\Filament\Resources\InventoryResource::getUrl()">رجوع للمخزون</x-filament::button>
        </div>
    </x-filament::section>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}
        <div class="flex flex-wrap items-center gap-3">
            <x-filament::button type="submit" icon="heroicon-o-check" wire:loading.attr="disabled" wire:target="save">حفظ قطع المخزون</x-filament::button>
            <span class="text-sm text-gray-500 dark:text-gray-400">حفظ بيانات القطع فقط؛ لا يتم بيع أو حذف أي قطعة من هنا.</span>
        </div>
    </form>
</x-filament-panels::page>
