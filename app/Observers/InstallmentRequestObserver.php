<?php

namespace App\Observers;

use App\Domain\Applications\ApplicationStateMachine;
use App\Domain\Applications\ApplicationTransitionException;
use App\Domain\Applications\StaffDecisionService;
use App\Jobs\SendWhatsappStatusNotification;
use App\Models\ApplicationEvent;
use App\Models\InstallmentRequest;
use App\Models\LegacyStatusMapping;
use Illuminate\Support\Facades\Log;

class InstallmentRequestObserver
{
    // Owner 2026-10-04: every change the customer should know about reaches
    // him on WhatsApp. new / new_request / transferred are internal steps.
    private const NOTIFIABLE_STATUSES = ['pending', 'work_check', 'approved', 'rejected', 'paused', 'delivered', 'canceled'];

    public function updated(InstallmentRequest $model): void
    {
        $statusChanged = $model->wasChanged('status');
        $status = (string) $model->status;

        // Staff can also change what they need from the customer on a
        // request that is already paused - that is a new message too.
        $actionChanged = $status === 'paused' && $model->wasChanged('customer_action') && filled($model->customer_action);

        if (! $statusChanged && ! $actionChanged) {
            return;
        }

        if ($statusChanged) {
            $this->syncApplicationStatus($model, $status);
        }

        if (! in_array($status, self::NOTIFIABLE_STATUSES, true)) {
            return;
        }

        $reason = $this->reasonText($model->checks_report);
        $decisions = app(StaffDecisionService::class);

        if ($status === 'paused') {
            try {
                $decisions->requestFromCustomer($model, $reason, $statusChanged ? (string) $model->getOriginal('status') : null);
            } catch (\Throwable $e) {
                // Saving the request must never fail because of the bot side.
                Log::warning('Staff request to customer failed', ['installment_request_id' => $model->id, 'error' => $e->getMessage()]);
            }
        }

        $decisions->logDecision($model, $status, $reason);

        // Owner 2026-10-04: the customer hears the moment staff change it.
        // If staff click on to another status, the job still skips a
        // status that is no longer current.
        SendWhatsappStatusNotification::dispatch($model->id, $status, $reason, (string) $model->customer_action);
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
            return trim(implode("\n", array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $checksReport))));
        }

        if (is_string($checksReport) && trim($checksReport) !== '') {
            return trim($checksReport);
        }

        return null;
    }
}
