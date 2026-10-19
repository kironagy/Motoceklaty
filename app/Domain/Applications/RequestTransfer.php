<?php

namespace App\Domain\Applications;

use App\Filament\Resources\DeliveryResource;
use App\Models\InstallmentRequest;
use App\Models\Staff;
use App\Support\StaffNotifier;

/**
 * Moving a request to another employee. A request owned by the owner (is_hitler) only moves with his
 * OK: the transfer waits on the request (pending_staff_id / transfer_requested_by), he gets a
 * notification and approves or refuses it from the deliveries page.
 */
class RequestTransfer
{
    /** @return string moved | asked */
    public static function move(InstallmentRequest $request, Staff $to, Staff $by): string
    {
        if ($request->canBeReassignedBy($by)) {
            $request->update([
                'staff_id' => $to->id,
                'pending_staff_id' => null,
                'transfer_requested_by' => null,
                'transfer_requested_at' => null,
            ]);

            return 'moved';
        }

        $request->forceFill([
            'pending_staff_id' => $to->id,
            'transfer_requested_by' => $by->id,
            'transfer_requested_at' => now(),
            'transfer_rejected_at' => null,
        ])->saveQuietly();

        $owner = $request->staff;
        $title = "{$by->name} بيحاول ياخد منك طلب #{$request->id}";
        $message = "{$by->name} عايز يحوّل الطلب #{$request->id} ({$request->applicant_name}) لـ {$to->name}. موافق؟ افتح «طلبات التحويل» في تسليم الطلبات.";
        $url = DeliveryResource::getUrl('index', ['activeTab' => 'transfers']);

        if ($owner?->is_hitler) {
            StaffNotifier::send($owner->id, $title, $message, $url, 'transfer-'.$request->id);
        } else {
            StaffNotifier::owners($title, $message, $url, 'transfer-'.$request->id);
        }

        return 'asked';
    }

    public static function isWaiting(InstallmentRequest $request): bool
    {
        return $request->pending_staff_id !== null && $request->transfer_requested_by !== null;
    }

    public static function approve(InstallmentRequest $request, Staff $owner): void
    {
        abort_unless($owner->is_hitler && static::isWaiting($request), 403);

        $to = Staff::find($request->pending_staff_id);
        $requester = $request->transfer_requested_by;

        $request->update([
            'staff_id' => $to?->id ?? $request->staff_id,
            'pending_staff_id' => null,
            'transfer_requested_by' => null,
            'transfer_requested_at' => null,
        ]);

        StaffNotifier::send(
            (int) $requester,
            "هتلر وافق على تحويل الطلب #{$request->id}",
            "الطلب #{$request->id} اتحول لـ ".($to?->name ?? '—').'.',
            DeliveryResource::getUrl('edit', ['record' => $request]),
            'transfer-'.$request->id,
        );
    }

    public static function reject(InstallmentRequest $request, Staff $owner): void
    {
        abort_unless($owner->is_hitler && static::isWaiting($request), 403);

        $requester = $request->transfer_requested_by;

        $request->forceFill([
            'pending_staff_id' => null,
            'transfer_requested_by' => null,
            'transfer_requested_at' => null,
            'transfer_rejected_at' => now(),
        ])->saveQuietly();

        StaffNotifier::send(
            (int) $requester,
            "هتلر رفض تحويل الطلب #{$request->id}",
            'هتلر مرضيش يحرامي 😂',
            DeliveryResource::getUrl('index'),
            'transfer-'.$request->id,
        );
    }
}
