<?php

namespace App\Domain\Applications;

use App\Models\Application;
use App\Models\ApplicationEvent;

/**
 * T13 §2: an explicit allowed-transition map, not an if-chain. Every
 * transition writes an application_events row; a disallowed one throws
 * ApplicationTransitionException('TRANSITION_NOT_ALLOWED').
 */
class ApplicationStateMachine
{
    private const TRANSITIONS = [
        'collecting' => ['submitted', 'withdrawn', 'expired'],
        'submitted' => ['under_review', 'approved', 'rejected', 'needs_more_info'],
        'under_review' => ['approved', 'rejected', 'needs_more_info'],
        'needs_more_info' => ['collecting'],
    ];

    /**
     * @param  array<string, mixed>  $eventData
     */
    public function transition(Application $application, string $toStatus, string $type, string $actor, array $eventData = []): void
    {
        $fromStatus = $application->status;
        $allowed = self::TRANSITIONS[$fromStatus] ?? [];

        if (! in_array($toStatus, $allowed, true)) {
            throw new ApplicationTransitionException(
                'TRANSITION_NOT_ALLOWED',
                "Cannot transition application {$application->id} from {$fromStatus} to {$toStatus}."
            );
        }

        $application->update(['status' => $toStatus, 'last_activity_at' => now()]);

        ApplicationEvent::create([
            'application_id' => $application->id,
            'type' => $type,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor' => $actor,
            'data' => $eventData,
        ]);
    }

    public function canTransition(Application $application, string $toStatus): bool
    {
        return in_array($toStatus, self::TRANSITIONS[$application->status] ?? [], true);
    }
}
