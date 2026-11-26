<?php

namespace App\Domain\Asfoora;

use App\Filament\Resources\DeliveryResource;
use App\Models\InstallmentRequest;
use App\Models\Staff;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Models\Activity;

/**
 * Owner 2026-11-26: "أي أكشن يتم في السيستم يتبعت بيه إشعار لهتلر: الحق فلان نقل طلب لفلان"
 * - every action an employee does (each activity_log entry with a staff causer) reaches the owners
 * (is_hitler, not the status parking accounts, not the one who did it) as a notification and a push.
 * Not: a request being uploaded (he said so), the system's or the bot's own moves (no causer - the
 * 48 h parking alone moves dozens), and the same employee editing the same thing again within
 * 3 minutes (one alert for a burst of edits). A transfer or a status change always alerts.
 */
class ActionAlerts
{
    private const FIELDS = [
        'applicant_name' => 'الاسم', 'applicant_phone' => 'التليفون', 'applicant_phone_2' => 'التليفون التاني', 'machine_id' => 'المكنة',
        'installment_type' => 'النظام', 'machine_installment_price' => 'سعر التقسيط', 'deposit' => 'المقدم', 'months' => 'المدة',
        'checks_report' => 'السبب', 'notes' => 'الملاحظات', 'customer_action' => 'المطلوب من العميل', 'employee_editable' => 'تعديل الموظف',
    ];

    private const MODELS = [
        'Letter' => 'جواب', 'TreasuryExpense' => 'مصروف خزنة', 'TreasuryExpenseItem' => 'بند مصروفات',
        'Inventory' => 'مخزن', 'InventoryItem' => 'صنف مخزن', 'InstallmentRequest' => 'طلب',
    ];

    public static function enabled(): bool
    {
        return (bool) config('asfoora.action_alerts', true);
    }

    public static function handle(Activity $activity): void
    {
        if (! self::enabled() || ! $activity->causer_id || ! str_ends_with((string) $activity->causer_type, 'Staff')) {
            return;
        }
        $model = class_basename((string) $activity->subject_type);
        $event = (string) ($activity->event ?: $activity->description);
        // uploading a request is the daily work - not an alert
        if ($model === 'InstallmentRequest' && $event === 'created') {
            return;
        }

        [$text, $always] = self::describe($activity, $model, $event);
        if ($text === null) {
            return;
        }
        if (! $always && ! Cache::add('asfoora-alert:'.$activity->causer_id.':'.$model.':'.$activity->subject_id, 1, now()->addMinutes(3))) {
            return;
        }

        $url = $model === 'InstallmentRequest' && $activity->subject_id
            ? DeliveryResource::getUrl('edit', ['record' => $activity->subject_id], panel: 'admin') : null;
        $owners = Staff::where('is_hitler', true)->where('id', '!=', $activity->causer_id)
            ->whereNotIn('email', array_column(InstallmentRequest::PARKING_ACCOUNTS, 0))->pluck('id');

        // sent after the response: the employee's save never waits for the pushes
        dispatch(function () use ($owners, $text, $url, $activity) {
            foreach ($owners as $id) {
                \App\Models\Notification::create(['user_id' => $id, 'title' => 'الحق! 👀', 'message' => $text, 'is_read' => false]);
                try {
                    app(\App\Services\PushNotificationService::class)->sendToUser(userId: $id, title: 'الحق! 👀', body: $text, url: $url, tag: 'action-'.$activity->id);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        })->afterResponse();
    }

    /** @return array{0: ?string, 1: bool} [the sentence, alert even within the 3-minute window] */
    public static function describe(Activity $activity, string $model, string $event): array
    {
        $who = Staff::whereKey($activity->causer_id)->value('name') ?? 'حد';
        $p = $activity->properties?->toArray() ?? [];
        $new = (array) ($p['attributes'] ?? []);
        $old = (array) ($p['old'] ?? []);
        $label = self::MODELS[$model] ?? $model;
        $id = $activity->subject_id;

        if ($model !== 'InstallmentRequest') {
            $verb = ['created' => 'ضاف', 'updated' => 'عدّل', 'deleted' => 'مسح'][$event] ?? $event;

            return ["{$who} {$verb} {$label} #{$id}", $event !== 'updated'];
        }

        $customer = InstallmentRequest::withTrashed()->whereKey($id)->value('applicant_name');
        $ref = "طلب #{$id}".($customer ? " ({$customer})" : '');
        $names = fn ($staffId) => $staffId ? (Staff::whereKey($staffId)->value('name') ?? "#{$staffId}") : 'من غير موظف';

        if ($event === 'deleted') {
            return ["{$who} مسح {$ref}", true];
        }
        if (array_key_exists('staff_id', $new) && ($old['staff_id'] ?? null) !== $new['staff_id']) {
            return ["{$who} نقل {$ref} من ".$names($old['staff_id'] ?? null).' لـ '.$names($new['staff_id']), true];
        }
        if (array_key_exists('status', $new)) {
            $from = DeliveryResource::STATUS_LABELS[$old['status'] ?? ''] ?? ($old['status'] ?? '');
            $to = DeliveryResource::STATUS_LABELS[$new['status']] ?? $new['status'];
            $why = DeliveryResource::reasonText($new['checks_report'] ?? null);

            return ["{$who} غيّر حالة {$ref}".($from ? " من {$from}" : '')." لـ {$to}".($why ? ' — '.mb_substr($why, 0, 80) : ''), true];
        }
        $fields = array_values(array_unique(array_map(fn ($k) => self::FIELDS[$k] ?? null, array_keys($new))));
        $fields = array_filter($fields);
        if ($fields === [] && $activity->description && $activity->description !== 'updated') {
            return ["{$who}: ".mb_substr((string) $activity->description, 0, 120)." — {$ref}", false];
        }

        return $fields === [] ? [null, false] : ["{$who} عدّل في {$ref}: ".implode('، ', $fields), false];
    }
}
