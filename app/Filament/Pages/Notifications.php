<?php

namespace App\Filament\Pages;

use App\Models\InstallmentRequest;
use App\Models\Notification;
use Filament\Pages\Page;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\WithPagination;

class Notifications extends Page
{
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-bell';
    protected static string $view = 'filament.pages.notifications';

    /** عدد الإشعارات المعروضة في كل صفحة. */
    private const PER_PAGE = 20;

    /**
     * لا نستخدم get() هنا؛ paginate() تجلب 20 سجلًا فقط من قاعدة البيانات.
     */
    public function getNotificationsProperty(): LengthAwarePaginator
    {
        return Notification::query()
            ->where('user_id', Auth::id())
            ->latest('created_at')
            ->paginate(self::PER_PAGE);
    }

    protected function getViewData(): array
    {
        return [
            'notifications' => $this->notifications,
        ];
    }

    public function markAllAsRead(): void
    {
        Notification::where('user_id', Auth::id())
            ->where('is_read', false)
            ->update(['is_read' => true]);

        $this->resetPage();
        $this->dispatch('refresh-bell');
    }

    public function markAsRead(int $id): void
    {
        Notification::where('id', $id)
            ->where('user_id', Auth::id())
            ->update(['is_read' => true]);

        $this->dispatch('refresh-bell');
    }

    public function approveTransfer(int $id): void
    {
        try {
            $notification = Notification::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (! $notification) {
                return;
            }

            $data = json_decode($notification->data, true);
            $requestId = $data['request_id'] ?? null;

            if (! $requestId) {
                return;
            }

            $request = InstallmentRequest::find($requestId);

            if (! $request) {
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

            $notification->update(['is_read' => true]);

            $this->dispatch('refresh-bell');

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

    public function rejectTransfer(int $id): void
    {
        $notification = Notification::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if (! $notification) {
            return;
        }

        $data = json_decode($notification->data, true);
        $requestId = $data['request_id'] ?? null;

        if (! $requestId) {
            return;
        }

        $request = InstallmentRequest::find($requestId);

        if ($request) {
            $request->update([
                'pending_staff_id' => null,
                'transfer_requested_by' => null,
                'transfer_requested_at' => null,
            ]);
        }

        $notification->update(['is_read' => true]);

        $this->dispatch('refresh-bell');

        \Filament\Notifications\Notification::make()
            ->title('تم رفض طلب التحويل')
            ->danger()
            ->send();
    }
}
