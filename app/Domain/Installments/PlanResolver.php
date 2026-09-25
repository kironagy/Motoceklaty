<?php

namespace App\Domain\Installments;

use App\Models\InstallmentPlan;
use App\Models\Machine;
use App\Support\ArabicTextNormalizer;

/**
 * Turns what the customer said ("امان سنة ونص") into a plan row. Tool
 * results don't survive between turns, so a bare plan_id was guessed
 * (always 1 = عبد اللطيف جميل 18 months) while the customer was told
 * "أمان" - the wrong price was quoted and saved to the application.
 * System name + months win over plan_id whenever they are given.
 *
 * Every tool that takes plan arguments goes through resolveStrict(): an
 * argument is either used or rejected with a reason - "months: 24" with no
 * system used to be dropped silently while the tool reported success, and
 * the application was left with no plan at all.
 */
class PlanResolver
{
    public function resolve(?Machine $machine, ?int $planId, ?string $systemName, ?int $months): ?InstallmentPlan
    {
        if ($systemName !== null && trim($systemName) !== '' && $months !== null) {
            return $this->byNameAndMonths($machine, $systemName, $months);
        }

        return $planId !== null ? InstallmentPlan::where('is_active', true)->find($planId) : null;
    }

    /**
     * @param  InstallmentPlan|null  $current  the plan already on the application, if any - a lone
     *                                         "months" or "system" changes only that part of it
     * @return InstallmentPlan|null null only when no plan argument was given at all
     *
     * @throws PlanResolutionException
     */
    public function resolveStrict(?Machine $machine, ?int $planId, ?string $systemName, ?int $months, ?InstallmentPlan $current = null): ?InstallmentPlan
    {
        $systemName = $systemName !== null && trim($systemName) !== '' ? $systemName : null;

        if ($planId === null && $systemName === null && $months === null) {
            return null;
        }

        $current?->loadMissing('installmentSystem');

        if ($systemName === null && $months !== null && $planId === null) {
            if (! $current) {
                throw new PlanResolutionException('SYSTEM_REQUIRED', 'months was given without installment_system. Ask which system (or use the one the customer already chose); see get_installment_options.');
            }

            $systemName = (string) $current->installmentSystem?->name;
        }

        if ($systemName !== null && $months === null) {
            if ($current && $this->sameSystem($current, $systemName)) {
                $months = $current->months;
            } elseif ($planId === null) {
                throw new PlanResolutionException('MONTHS_REQUIRED', 'installment_system was given without months. Ask for the duration; see get_installment_options.');
            }
        }

        $plan = $systemName !== null && $months !== null
            ? $this->byNameAndMonths($machine, $systemName, $months)
            : InstallmentPlan::with('installmentSystem')->where('is_active', true)->find($planId);

        if (! $plan) {
            throw new PlanResolutionException('PLAN_NOT_AVAILABLE_FOR_MOTORCYCLE', $this->availableHint($machine, $systemName));
        }

        if ($planId !== null && $plan->id !== $planId) {
            throw new PlanResolutionException('CONFLICTING_PLAN_ARGUMENTS', 'plan_id does not match installment_system + months. Send installment_system + months only.');
        }

        if ($machine && ! $machine->installmentSystems()->where('installment_systems.id', $plan->installment_system_id)->exists()) {
            throw new PlanResolutionException('PLAN_NOT_AVAILABLE_FOR_MOTORCYCLE', $this->availableHint($machine, $systemName));
        }

        return $plan;
    }

    private function byNameAndMonths(?Machine $machine, string $systemName, int $months): ?InstallmentPlan
    {
        $plans = InstallmentPlan::with('installmentSystem')
            ->where('is_active', true)
            ->where('months', $months)
            ->whereHas('installmentSystem', fn ($q) => $q->where('is_active', true))
            ->when($machine, fn ($q) => $q->whereIn('installment_system_id', $machine->installmentSystems()->pluck('installment_systems.id')))
            ->get();

        $wanted = $this->clean($systemName);
        $name = fn (InstallmentPlan $p) => $this->clean((string) $p->installmentSystem?->name);

        // Exact name first, so "امان" is never read as "امان - الجيزة".
        return $plans->first(fn ($p) => $name($p) === $wanted)
            ?? $plans->first(fn ($p) => str_contains($name($p), $wanted) || str_contains($wanted, $name($p)));
    }

    private function sameSystem(InstallmentPlan $plan, string $systemName): bool
    {
        return $this->clean((string) $plan->installmentSystem?->name) === $this->clean($systemName);
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', ArabicTextNormalizer::normalize($value)));
    }

    private function availableHint(?Machine $machine, ?string $systemName): string
    {
        if (! $machine) {
            return 'No such plan. Check get_installment_options.';
        }

        $available = InstallmentPlan::with('installmentSystem')
            ->where('is_active', true)
            ->whereIn('installment_system_id', $machine->installmentSystems()->pluck('installment_systems.id'))
            ->get()
            ->groupBy(fn ($p) => trim((string) $p->installmentSystem?->name))
            ->map(fn ($plans, $name) => $name.': '.$plans->pluck('months')->sort()->implode('/').' months')
            ->implode('; ');

        return 'No such plan for this motorcycle. Available: '.$available;
    }
}
