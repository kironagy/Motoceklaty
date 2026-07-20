<x-filament::page>
<div dir="rtl" class="space-y-6">

    <div class="flex justify-between items-center">
        <h2 class="text-2xl font-bold">الإشعارات</h2>

        <x-filament::button
            wire:click="markAllAsRead"
            color="warning"
            size="sm"
        >
            تعليم الكل كمقروء
        </x-filament::button>
    </div>

    @forelse($notifications as $notification)

        @php
            $data = json_decode($notification['data'] ?? '{}', true);
            $requestId = $data['request_id'] ?? null;

            $link = $requestId 
                ? route('filament.admin.resources.deliveries.edit', $requestId)
                : null;

            $isTransfer = ($notification['type'] ?? null) === 'transfer_request';
        @endphp

        <x-filament::card
            class="{{ !$notification['is_read'] ? 'ring-2 ring-warning-500' : '' }}"
        >

            <div class="flex justify-between items-start gap-6">

                <div class="space-y-2 w-full">

                    <div class="flex items-center gap-2">
                        <h3 class="text-lg font-semibold">
                            {{ $notification['title'] }}
                        </h3>

                        @if($isTransfer)
                            <x-filament::badge color="info">
                                طلب تحويل
                            </x-filament::badge>
                        @endif
                    </div>

                    <p class="text-sm text-gray-500">
                        {{ $notification['message'] }}
                    </p>

                    <p class="text-xs text-gray-400">
                        {{ \Carbon\Carbon::parse($notification['updated_at'])
                            ->timezone('Africa/Cairo')
                            ->format('d/m/Y - H:i') }}
                    </p>

                    @if($isTransfer && !$notification['is_read'])

                        <div class="flex gap-3 pt-3">

                            <x-filament::button
                                wire:click.prevent="approveTransfer({{ $notification['id'] }})"
                                color="success"
                                size="sm"
                            >
                                موافقة
                            </x-filament::button>

                            <x-filament::button
                              wire:click.prevent="rejectTransfer({{ $notification['id'] }})"
                                color="danger"
                                size="sm"
                            >
                                رفض
                            </x-filament::button>

                        </div>

                    @endif

                    @if($link)
                        <div class="pt-2">
                            <a href="{{ $link }}"
                               class="text-sm text-primary-600 hover:underline">
                                فتح الطلب →
                            </a>
                        </div>
                    @endif

                </div>

                @if(!$notification['is_read'])
                    <x-filament::button
                        wire:click="markAsRead({{ $notification['id'] }})"
                        color="gray"
                        size="xs"
                    >
                        تعليم كمقروء
                    </x-filament::button>
                @endif

            </div>

        </x-filament::card>

    @empty

        <x-filament::card>
            <div class="text-center text-gray-500 py-8">
                لا توجد إشعارات حالياً.
            </div>
        </x-filament::card>

    @endforelse

</div>
</x-filament::page>
