<?php

namespace App\Observers;

use App\Models\InstallmentPlan;
use App\Models\InstallmentSystem;

/**
 * Staff keep editing plans through the existing `plans` JSON repeater in
 * InstallmentSystemResource (unchanged, so the website calculator keeps
 * working) - `installment_plans` (T12) is a queryable projection of it,
 * resynced here rather than maintained by hand in two places. Same pattern
 * as MachineObserver/motorcycle_images (DEC-11).
 *
 * Plans are updated in place, never deleted: applications reference them,
 * and switching a system off ("مايلو") failed with a foreign key error
 * because the resync deleted plans that submitted applications point to.
 * A duration removed from the JSON is only deactivated.
 */
class InstallmentSystemObserver
{
    public function saved(InstallmentSystem $system): void
    {
        // Not gated on wasChanged('plans'): Eloquent only populates
        // wasChanged() from performUpdate(), never performInsert(), so a
        // guard here would silently skip the very first sync on create.
        $existing = InstallmentPlan::where('installment_system_id', $system->id)->orderBy('id')->get()->groupBy('months');
        $kept = [];

        foreach ((array) ($system->plans ?? []) as $plan) {
            if (! is_array($plan) || ! isset($plan['months'], $plan['interest'])) {
                continue;
            }

            $months = (int) $plan['months'];

            if (isset($kept[$months])) {
                continue;
            }

            $row = $existing->get($months)?->first() ?? new InstallmentPlan(['installment_system_id' => $system->id, 'months' => $months]);
            $row->fill(['interest_percent' => (float) $plan['interest'], 'is_active' => true])->save();
            $kept[$months] = $row->id;
        }

        InstallmentPlan::where('installment_system_id', $system->id)
            ->whereNotIn('id', array_values($kept))
            ->update(['is_active' => false]);
    }

    public function deleted(InstallmentSystem $system): void
    {
        InstallmentPlan::where('installment_system_id', $system->id)->update(['is_active' => false]);
    }
}
