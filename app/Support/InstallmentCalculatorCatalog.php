<?php

namespace App\Support;

use App\Models\Brand;
use App\Models\Machine;

/**
 * What the website's installment calculator can offer: active machines,
 * each with the systems + active plans linked to it through the
 * machine_installment_system pivot - the same rule as
 * InstallmentOptionsService::forMachine(), loaded in one go for the page.
 * The numbers themselves always come from InstallmentCalculator on the
 * server (InstallmentController::calculate).
 */
class InstallmentCalculatorCatalog
{
    public static function payload(): array
    {
        $request = request();
        if ($request->attributes->has(self::class)) {
            return $request->attributes->get(self::class);
        }

        $machines = Machine::query()
            ->where('is_active', true)
            ->with(['installmentSystems.installmentPlans' => fn ($q) => $q->where('is_active', true)->orderBy('months')])
            ->orderBy('name')
            ->get(['id', 'name', 'brand_id', 'category', 'cash_price', 'installment_price', 'display_image']);

        $brandIds = $machines->pluck('brand_id')->unique()->filter();

        $payload = [
            'brands' => Brand::query()
                ->whereIn('id', $brandIds)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($brand) => ['id' => $brand->id, 'name' => trim($brand->name)])
                ->values()
                ->all(),
            'machines' => $machines->map(fn (Machine $machine) => [
                'id' => $machine->id,
                'name' => $machine->name,
                'brand_id' => $machine->brand_id,
                'category' => $machine->category,
                'image' => $machine->displayImageUrl(),
                'cash_price' => (float) $machine->cash_price,
                'systems' => $machine->installmentSystems
                    ->filter(fn ($system) => $system->is_active !== false && $system->installmentPlans->isNotEmpty())
                    ->sortByDesc('priority')
                    ->map(fn ($system) => [
                        'id' => $system->id,
                        'name' => trim($system->name),
                        'plans' => $system->installmentPlans->map(fn ($plan) => [
                            'months' => (int) $plan->months,
                            'interest' => (float) $plan->interest_percent,
                        ])->values()->all(),
                    ])
                    ->values()
                    ->all(),
            ])->filter(fn ($machine) => $machine['systems'] !== [])->values()->all(),
        ];

        $request->attributes->set(self::class, $payload);

        return $payload;
    }
}
