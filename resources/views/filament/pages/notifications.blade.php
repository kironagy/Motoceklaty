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
     <div id="push-notification-banner" class="push-banner" dir="rtl" style="display: none;">
    <div class="push-banner__glow"></div>

    <div class="push-banner__content">
        <div class="push-banner__icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 7-3 9h18c0-2-3-2-3-9"/>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
        </div>

        <div class="push-banner__text">
            <span class="push-banner__badge">تنبيهات فورية</span>
            <h3>فعّل إشعارات الهاتف</h3>
            <p>خليك متابع كل جديد في الطلبات، حتى لو قفلت الداشبورد.</p>
        </div>

        <div class="push-banner__actions">
            <button id="enable-push-notifications" type="button" class="push-banner__enable">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="m5 12 4 4L19 6"/>
                </svg>
                تفعيل الإشعارات
            </button>

            <button id="dismiss-push-notifications" type="button" class="push-banner__later">
                ليس الآن
            </button>
        </div>
    </div>

<style>
    .push-banner {
        position: relative;
        overflow: hidden;
        width: 100%;
        margin: 0 0 24px;
        border: 1px solid rgba(245, 158, 11, .28);
        border-radius: 18px;
        background:
            radial-gradient(circle at 92% 20%, rgba(245, 158, 11, .14), transparent 28%),
            linear-gradient(135deg, #1d2d46 0%, #17253a 100%);
        box-shadow: 0 12px 32px rgba(0, 0, 0, .16);
    }

    .push-banner__glow {
        position: absolute;
        top: -95px;
        right: -50px;
        width: 230px;
        height: 230px;
        border-radius: 50%;
        background: rgba(245, 158, 11, .10);
        filter: blur(8px);
        pointer-events: none;
    }

    .push-banner__content {
        position: relative;
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 18px 22px;
    }

    .push-banner__icon {
        display: grid;
        flex: 0 0 auto;
        width: 52px;
        height: 52px;
        place-items: center;
        border: 1px solid rgba(251, 191, 36, .35);
        border-radius: 15px;
        color: #fbbf24;
        background: rgba(245, 158, 11, .12);
    }

    .push-banner__icon svg {
        width: 27px;
        height: 27px;
    }

    .push-banner__text {
        flex: 1;
        min-width: 0;
    }

    .push-banner__badge {
        display: inline-block;
        margin-bottom: 4px;
        color: #fbbf24;
        font-size: 11px;
        font-weight: 700;
    }

    .push-banner__text h3 {
        margin: 0;
        color: #fff;
        font-size: 19px;
        font-weight: 800;
        line-height: 1.35;
    }

    .push-banner__text p {
        margin: 4px 0 0;
        color: #b9c6d8;
        font-size: 13px;
    }

    .push-banner__actions {
        display: flex;
        align-items: center;
        gap: 10px;
        flex: 0 0 auto;
    }

    .push-banner__enable {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 42px;
        padding: 0 17px;
        border: 0;
        border-radius: 11px;
        color: #101827;
        background: linear-gradient(135deg, #fbbf24, #f59e0b);
        box-shadow: 0 8px 18px rgba(245, 158, 11, .20);
        font-weight: 800;
        font-size-size: 13px;
        cursor: pointer;
        transition: .2s ease;
    }

    .push-banner__enable:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 24px rgba(245, 158, 11, .32);
    }

    .push-banner__enable svg {
        width: 17px;
        height: 17px;
    }

    .push-banner__later {
        padding: 10px 8px;
        border: 0;
        color: #a7b3c5;
        background: transparent;
        font-size: 13px;
        cursor: pointer;
    }

    .push-banner__later:hover {
        color: #fff;
    }

    @media (max-width: 640px) {
        .push-banner__content {
            align-items: flex-start;
            flex-wrap: wrap;
            padding: 16px;
        }

        .push-banner__actions {
            width: 100%;
            padding-right: 68px;
        }

        .push-banner__enable {
            flex: 1;
        }
    }
</style>
<script>
    function initializePushNotificationsBanner() {
        const banner = document.getElementById('push-notification-banner');
        const enableButton = document.getElementById('enable-push-notifications');
        const dismissButton = document.getElementById('dismiss-push-notifications');

        if (!banner || !enableButton || !('Notification' in window) || !('serviceWorker' in navigator)) {
            return;
        }

        // تم التفعيل مسبقًا: لا نعرض زر السماح، لكن نحدّث اشتراك الجهاز إن لزم.
        if (Notification.permission === 'granted') {
            banner.style.display = 'none';
            registerPushSubscription();
            return;
        }

        // المستخدم رفض سابقًا؛ المتصفح لن يعرض نافذة السماح مرة أخرى.
        if (Notification.permission === 'denied') {
            banner.style.display = 'block';
            enableButton.disabled = true;
            enableButton.innerHTML = 'الإشعارات محجوبة من المتصفح';
            return;
        }

        // default: المستخدم لم يوافق أو يرفض بعد، لذلك نعرض زر التفعيل.
        banner.style.display = 'block';

        if (enableButton.dataset.pushListenerAdded === 'true') {
            return;
        }

        enableButton.dataset.pushListenerAdded = 'true';

        enableButton.addEventListener('click', async () => {
            enableButton.disabled = true;
            enableButton.innerHTML = 'جارِ التفعيل...';

            try {
                const permission = await Notification.requestPermission();

                if (permission !== 'granted') {
                    enableButton.disabled = false;
                    enableButton.innerHTML = 'تفعيل الإشعارات';
                    return;
                }

                await registerPushSubscription();
                banner.style.display = 'none';
            } catch (error) {
                console.error(error);
                enableButton.disabled = false;
                enableButton.innerHTML = 'حاول مرة أخرى';
            }
        });

        dismissButton?.addEventListener('click', () => {
            banner.style.display = 'none';
        });
    }

    async function registerPushSubscription() {
        const registration = await navigator.serviceWorker.register('/sw.js');
        const vapidPublicKey = '{{ config('services.web_push.public_key') }}';

        if (!vapidPublicKey) {
            throw new Error('VAPID public key is missing.');
        }

        let subscription = await registration.pushManager.getSubscription();

        if (!subscription) {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
            });
        }

        await fetch('{{ route('push-subscriptions.store') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
            },
            body: JSON.stringify({
                ...subscription.toJSON(),
                contentEncoding: 'aes128gcm',
            }),
        });
    }

    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
            .replace(/-/g, '+')
            .replace(/_/g, '/');

        return Uint8Array.from(
            [...window.atob(base64)].map((character) => character.charCodeAt(0))
        );
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializePushNotificationsBanner);
    } else {
        initializePushNotificationsBanner();
    }

    document.addEventListener('livewire:navigated', initializePushNotificationsBanner);
</script>
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
@if($notifications->hasPages())
    <div class="pt-2" dir="ltr">
        {{ $notifications->onEachSide(1)->links() }}
    </div>
@endif
</div>
</x-filament::page>
