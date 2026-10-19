<?php

namespace App\Filament\Concerns;

use App\Support\StaffNotifier;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * Advances and vacations: an employee files a request, and only a reviewer decides it.
 *
 * The status never sits in the form - an employee could pick "approved" while creating his own
 * request. The decision is the approve / reject actions, open to full access (or the owner) and
 * never on the reviewer's own request, unless he is the owner.
 */
trait ReviewsStaffRequests
{
    public const STATUSES = [
        'pending' => 'تحت المراجعة',
        'approved' => 'تمت الموافقة',
        'rejected' => 'مرفوضة',
    ];

    public const STATUS_COLORS = [
        'pending' => 'warning',
        'approved' => 'success',
        'rejected' => 'danger',
    ];

    /** What the request is, for notifications: "طلب السلفة" / "طلب الإجازة". */
    abstract protected static function requestNoun(): string;

    /** Runs after a decision is saved (vacations update the attendance). */
    protected static function afterDecision(Model $record, string $from): void {}

    public static function canReview(?Model $record): bool
    {
        $user = auth()->user();

        if (! $record || ! $user || ! static::fullAccess()) {
            return false;
        }

        return (bool) $user->is_hitler || (int) $record->staff_id !== (int) $user->id;
    }

    /** The employee changes his own request while it waits; a reviewer may correct it any time. */
    public static function canChangeDetails(?Model $record): bool
    {
        if (! $record) {
            return true;
        }

        if (static::canReview($record)) {
            return true;
        }

        return (int) $record->staff_id === (int) auth()->id() && $record->status === 'pending';
    }

    public static function decide(Model $record, string $status, ?string $notes): void
    {
        $from = (string) $record->status;

        $record->forceFill([
            'status' => $status,
            'admin_notes' => filled($notes) ? $notes : $record->admin_notes,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ])->save();

        static::afterDecision($record, $from);

        $noun = static::requestNoun();
        $label = static::STATUSES[$status];

        if ((int) $record->staff_id !== (int) auth()->id()) {
            StaffNotifier::send(
                (int) $record->staff_id,
                "{$noun}: {$label}",
                trim("{$noun} رقم {$record->id} {$label}".(filled($notes) ? " - {$notes}" : '')),
                static::getUrl('edit', ['record' => $record]),
                'staff-request-'.$record->getTable().'-'.$record->id,
            );
        }

        Notification::make()->title("{$noun}: {$label}")->success()->send();
    }

    /**
     * Approve / reject, for a table row or the edit page header.
     *
     * @param  class-string<\Filament\Actions\Action|\Filament\Tables\Actions\Action>  $actionClass
     */
    public static function decisionActions(string $actionClass): array
    {
        $make = fn (string $name, string $status, string $label, string $color, string $icon) => $actionClass::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (?Model $record) => static::canReview($record) && $record->status !== $status)
            ->form([
                Textarea::make('admin_notes')
                    ->label('ملاحظات للموظف')
                    ->rows(3)
                    ->required($status === 'rejected'),
            ])
            ->modalHeading(fn (Model $record) => $label.' - '.$record->staff?->name)
            ->modalSubmitActionLabel($label)
            ->action(function (Model $record, array $data, $livewire) use ($status) {
                abort_unless(static::canReview($record), 403);
                static::decide($record, $status, $data['admin_notes'] ?? null);

                if (method_exists($livewire, 'refreshFormData')) {
                    $livewire->refreshFormData(['status', 'admin_notes']);
                }
            });

        return [
            $make('approve', 'approved', 'موافقة', 'success', 'heroicon-o-check-circle'),
            $make('reject', 'rejected', 'رفض', 'danger', 'heroicon-o-x-circle'),
        ];
    }
}
