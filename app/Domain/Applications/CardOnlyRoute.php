<?php

namespace App\Domain\Applications;

use App\Models\Application;
use App\Models\ApplicationEvent;

/**
 * Owner 2026-10-04: "the important thing is that he applies" - a working man
 * who cannot bring the papers his work needs applies with his ID only, on
 * the free-work (عامل حر) rules: its financing cap, its installment. The
 * route is marked on the application so staff see why a salaried worker or
 * a shop owner came in as عامل حر.
 */
final class CardOnlyRoute
{
    public const EVENT = 'card_only_route';

    public static function mark(Application $application, string $quote): void
    {
        if (self::on($application)) {
            return;
        }

        ApplicationEvent::create([
            'application_id' => $application->id,
            'type' => self::EVENT,
            'actor' => 'ai',
            'data' => ['customer_words' => $quote],
            'created_at' => now(),
        ]);
    }

    /**
     * QA 2026-10-04: switched to the card-only route, the application kept
     * the employee plan with nothing down - 63,000 financed against the
     * free-work cap - and he was told "التقسيط مش متاح". The same duration
     * is priced again on the free-work terms (the bigger amount at pickup).
     */
    public static function reprice(Application $application): void
    {
        $application->loadMissing(['machine', 'installmentPlan']);

        if (! $application->machine || $application->status !== 'collecting') {
            return;
        }

        $months = $application->installmentPlan?->months;
        $offers = app(\App\Domain\Installments\BestOfferService::class)
            ->offers($application->machine, $application->customer_type_id, null, $months);
        $offer = $offers[0] ?? null;

        if ($offer === null) {
            return;
        }

        try {
            app(ApplicationService::class)->updateSelection(
                $application,
                null,
                \App\Models\InstallmentPlan::with('installmentSystem')->find($offer['plan_id']),
                (float) $offer['down_payment'],
                null,
            );
        } catch (\Throwable) {
            // the snapshot's blockers still say what is missing
        }
    }

    public static function on(Application $application): bool
    {
        return ApplicationEvent::where('application_id', $application->id)->where('type', self::EVENT)->exists();
    }
}
