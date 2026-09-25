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
 */
class InstallmentSystemObserver
{
    public function saved(InstallmentSystem $system): void
    {
        // Not gated on wasChanged('plans'): Eloquent only populates
        // wasChanged() from performUpdate(), never performInsert(), so a
        // guard here would silently skip the very first sync on create.
        // Resyncing unconditionally is cheap at this table's scale and
        // always correct on both create and update.
        InstallmentPlan::where('installment_system_id', $system->id)->delete();

        $rows = [];
        $now = now();

        foreach ((array) ($system->plans ?? []) as $plan) {
            if (! is_array($plan) || ! isset($plan['months'], $plan['interest'])) {
                continue;
            }

            $rows[] = [
                'installment_system_id' => $system->id,
                'months' => (int) $plan['months'],
                'interest_percent' => (float) $plan['interest'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            InstallmentPlan::insert($rows);
        }
    }

    public function deleted(InstallmentSystem $system): void
    {
        InstallmentPlan::where('installment_system_id', $system->id)->delete();
    }
}
