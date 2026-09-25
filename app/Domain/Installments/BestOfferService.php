<?php

namespace App\Domain\Installments;

use App\Models\InstallmentPlan;
use App\Models\Machine;

/**
 * Customers do not want to compare finance companies: "القسط كام على سنة"
 * deserves one answer - the upfront cash and the monthly payment - not a
 * list of five systems. For each duration this picks, among the systems
 * linked to the motorcycle whose conditions the customer meets (dashboard:
 * active, customer types, governorates, caps), the one that costs him least
 * in total (cash up front + all installments). Priority breaks ties.
 *
 * The showroom takes no down payment by default, only admin fees at pickup.
 * $noUpfront is for a customer who insists on paying nothing at all: only
 * the systems marked no_upfront_only are used then (the dearer 30%-a-year
 * one), and they never appear in a normal quote.
 */
class BestOfferService
{
    public function __construct(
        private readonly InstallmentCalculator $calculator,
        private readonly FinancingCapPolicy $caps,
    ) {
    }

    /**
     * @return array<int, array{months: int, plan_id: int, system: string, down_payment: float, admin_fee: float,
     *                          cash_due_upfront: float, monthly_payment: float, total_cost: float, cap: ?float}>
     *                          best offer per duration, shortest first
     */
    public function offers(Machine $machine, ?int $customerTypeId, ?string $governorate = null, ?int $months = null, ?float $downPayment = null, bool $noUpfront = false): array
    {
        $systems = $machine->installmentSystems()
            ->with(['installmentPlans' => fn ($q) => $q->where('is_active', true)])
            ->get()
            ->filter(fn ($system) => $system->acceptsCustomer($customerTypeId, $governorate))
            ->filter(fn ($system) => (bool) $system->no_upfront_only === $noUpfront);

        if ($noUpfront) {
            $downPayment = 0.0;
        }

        $best = [];

        foreach ($systems as $system) {
            $cap = $this->caps->capForSystem($system, $customerTypeId);
            $minimum = max(
                (float) ($system->minimum_down_payment ?? 0),
                $cap !== null ? $this->caps->minimumDownPayment($machine, $system, $cap) : 0.0,
            );
            $down = max($minimum, (float) ($downPayment ?? 0));

            foreach ($system->installmentPlans as $plan) {
                if ($months !== null && $plan->months !== $months) {
                    continue;
                }

                try {
                    $result = $this->calculator->calculate($machine, $system, $plan, $down);
                } catch (InstallmentCalculationException) {
                    continue;
                }

                $upfront = round($down + $result->administrativeFees);

                if ($noUpfront && $upfront > 0) {
                    continue;
                }
                $offer = [
                    'months' => $plan->months,
                    'plan_id' => $plan->id,
                    'system' => trim((string) $system->name),
                    'down_payment' => $down,
                    'admin_fee' => round($result->administrativeFees),
                    'cash_due_upfront' => $upfront,
                    'monthly_payment' => $result->monthlyInstallment,
                    'total_cost' => $upfront + $result->monthlyInstallment * $plan->months,
                    'cap' => $cap !== null && $minimum > (float) ($system->minimum_down_payment ?? 0) ? $cap : null,
                    'priority' => (int) $system->priority,
                ];

                $current = $best[$plan->months] ?? null;

                if ($current === null
                    || $offer['total_cost'] < $current['total_cost']
                    || ($offer['total_cost'] === $current['total_cost'] && $offer['priority'] > $current['priority'])) {
                    $best[$plan->months] = $offer;
                }
            }
        }

        ksort($best);

        return array_values(array_map(function (array $offer) {
            unset($offer['priority']);

            return $offer;
        }, $best));
    }

    /** The plan behind the best offer for this duration, if any system offers it. */
    public function bestPlan(Machine $machine, int $months, ?int $customerTypeId, ?string $governorate = null, ?float $downPayment = null): ?InstallmentPlan
    {
        $offer = $this->offers($machine, $customerTypeId, $governorate, $months, $downPayment)[0] ?? null;

        return $offer ? InstallmentPlan::with('installmentSystem')->find($offer['plan_id']) : null;
    }
}
