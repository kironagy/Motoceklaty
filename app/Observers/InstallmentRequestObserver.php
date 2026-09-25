<?php

namespace App\Observers;

use App\Domain\Applications\ApplicationStateMachine;
use App\Domain\Applications\ApplicationTransitionException;
use App\Jobs\SendWhatsappStatusNotification;
use App\Models\ApplicationEvent;
use App\Models\InstallmentRequest;
use App\Models\LegacyStatusMapping;

class InstallmentRequestObserver
{
    private const NOTIFIABLE_STATUSES = ['approved', 'rejected', 'paused'];

    public function updated(InstallmentRequest $model): void
    {
        if (! $model->wasChanged('status')) {
            return;
        }

        $status = (string) $model->status;

        $this->syncApplicationStatus($model, $status);

        if (! in_array($status, self::NOTIFIABLE_STATUSES, true)) {
            return;
        }

        SendWhatsappStatusNotification::dispatchSync(
            $model->id,
            $status,
            $this->reasonText($model->checks_report)
        );
    }

    /**
     * T14 §3 / DEC-21: the legacy status is never the source of truth for
     * the agent (the Application's own status is) - this only projects a
     * *known* mapping onto it, dashboard-editable via LegacyStatusMapping.
     * A legacy status with no mapping yet is logged for staff visibility
     * instead of guessed at.
     */
    private function syncApplicationStatus(InstallmentRequest $model, string $legacyStatus): void
    {
        $application = $model->application;

        if (! $application) {
            return;
        }

        $mapping = LegacyStatusMapping::where('legacy_status', $legacyStatus)->first();
        $targetStatus = $mapping?->application_status;

        if (! $targetStatus) {
            ApplicationEvent::create([
                'application_id' => $application->id,
                'type' => 'legacy_status_unmapped_change',
                'from_status' => $application->status,
                'to_status' => $application->status,
                'actor' => 'system',
                'data' => ['legacy_status' => $legacyStatus],
            ]);

            return;
        }

        if ($targetStatus === $application->status) {
            return;
        }

        try {
            app(ApplicationStateMachine::class)->transition(
                $application,
                $targetStatus,
                'legacy_status_synced',
                'staff',
                ['legacy_status' => $legacyStatus, 'installment_request_id' => $model->id]
            );
        } catch (ApplicationTransitionException) {
            // The mapped target isn't reachable from the application's current
            // status (e.g. it was already withdrawn by the customer) - leave
            // it as-is rather than force an invalid transition.
        }
    }

    private function reasonText(mixed $checksReport): ?string
    {
        if (is_array($checksReport)) {
            return trim(implode("\n", array_filter(array_map('strval', $checksReport))));
        }

        if (is_string($checksReport) && trim($checksReport) !== '') {
            return trim($checksReport);
        }

        return null;
    }
}
