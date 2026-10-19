<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\Staff;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\Log;

/** A dashboard notification (the bell) plus a browser push to one staff member. */
class StaffNotifier
{
    public static function send(int $staffId, string $title, string $message, ?string $url = null, ?string $tag = null): void
    {
        Notification::create([
            'user_id' => $staffId,
            'title' => $title,
            'message' => $message,
            'is_read' => false,
        ]);

        try {
            app(PushNotificationService::class)->sendToUser($staffId, $title, $message, $url, $tag);
        } catch (\Throwable $e) {
            Log::warning('Push notification failed', ['staff_id' => $staffId, 'error' => $e->getMessage()]);
        }
    }

    /** Every owner account (is_hitler). */
    public static function owners(string $title, string $message, ?string $url = null, ?string $tag = null): void
    {
        foreach (Staff::query()->where('is_hitler', true)->pluck('id') as $id) {
            static::send((int) $id, $title, $message, $url, $tag);
        }
    }
}
