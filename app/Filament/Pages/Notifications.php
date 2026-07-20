<?php

namespace App\Filament\Pages;

use App\Models\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class Notifications extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-bell';
    protected static string $view = 'filament.pages.notifications';

    public array $notifications = [];

    public function mount(): void
    {
        $this->loadNotifications();
    }
public function markAllAsRead()
{
    \App\Models\Notification::where('user_id', Auth::id())
        ->update(['is_read' => true]);

    $this->loadNotifications();

    $this->dispatch('refresh-bell');
}


    public function loadNotifications(): void
    {
        $this->notifications = Notification::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    public function markAsRead(int $id): void
    {
        Notification::where('id', $id)
            ->where('user_id', Auth::id())
            ->update(['is_read' => true]);

        // 🔄 إعادة تحميل الإشعارات بعد التحديث
        $this->loadNotifications();

        // 🔔 تحديث عدد الإشعارات بجانب أيقونة الجرس
        $this->dispatch('refresh-bell');
    }


public function approveTransfer($id)
{
    try {

        $notification = \App\Models\Notification::find($id);

        if (!$notification) {
            return;
        }

        $data = json_decode($notification->data, true);

        if (!isset($data['request_id'])) {
            return;
        }

        $request = \App\Models\InstallmentRequest::find($data['request_id']);

        if (!$request) {
            return;
        }

        if ($request->pending_staff_id) {
            $request->update([
                'staff_id' => $request->pending_staff_id,
                'pending_staff_id' => null,
                'transfer_requested_by' => null,
                'transfer_requested_at' => null,
            ]);
        }

        $notification->update([
            'is_read' => true,
        ]);

        $this->loadNotifications();

        \Filament\Notifications\Notification::make()
            ->title('تمت الموافقة على التحويل')
            ->success()
            ->send();

    } catch (\Throwable $e) {

        \Filament\Notifications\Notification::make()
            ->title('حصل خطأ')
            ->body($e->getMessage())
            ->danger()
            ->send();
    }
}

public function rejectTransfer($id)
{
    $notification = \App\Models\Notification::find($id);

    if (!$notification) return;

    $data = json_decode($notification->data, true);

    if (!isset($data['request_id'])) return;

    $request = \App\Models\InstallmentRequest::find($data['request_id']);

    if ($request) {
        $request->update([
            'pending_staff_id' => null,
            'transfer_requested_by' => null,
            'transfer_requested_at' => null,
        ]);
    }

    $notification->update([
        'is_read' => true,
    ]);

    $this->loadNotifications();

    \Filament\Notifications\Notification::make()
        ->title('تم رفض طلب التحويل')
        ->danger()
        ->send();
}
}
