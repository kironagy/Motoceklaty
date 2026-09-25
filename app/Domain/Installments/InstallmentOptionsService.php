<?php

namespace App\Domain\Installments;

use App\Models\Machine;

class InstallmentOptionsService
{
    /**
     * Systems and active plans linked to this machine, for the
     * get_installment_options tool (§6.5). Eligibility restrictions are
     * reported by check_eligibility (§6.7) separately - this call never
     * evaluates eligibility itself (plan T12 §5).
     */
    public function forMachine(Machine $machine): array
    {
        $systems = $machine->installmentSystems()
            ->with(['installmentPlans' => fn ($q) => $q->where('is_active', true)->orderBy('months')])
            ->get()
            ->filter(fn ($system) => $system->is_active !== false);

        return $systems
            ->filter(fn ($system) => $system->installmentPlans->isNotEmpty())
            ->map(fn ($system) => [
                'installment_system_id' => $system->id,
                'name' => $system->name,
                'administrative_fees_percent' => (float) $system->administrative_fees,
                'minimum_down_payment' => $system->minimum_down_payment !== null
                    ? (float) $system->minimum_down_payment
                    : null,
                'plans' => $system->installmentPlans->map(fn ($plan) => [
                    'installment_plan_id' => $plan->id,
                    'months' => $plan->months,
                    'interest_percent' => $plan->interest_percent,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
