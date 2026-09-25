<?php

namespace App\Observers;

use App\Domain\Catalog\CatalogService;
use App\Models\Machine;
use App\Models\MotorcycleImage;
use Illuminate\Support\Facades\DB;

class MachineObserver
{
    /**
     * Editors still use the existing `colors` repeater in MachineResource
     * (unchanged, so the website keeps working per plan constraint) -
     * `motorcycle_images` (DEC-11) is a read-optimized projection of it,
     * resynced here rather than maintained by hand in two places.
     */
    public function saved(Machine $machine): void
    {
        CatalogService::invalidateIndexCache();

        // Not gated on wasChanged('installment_systems') - see
        // InstallmentSystemObserver for why that check is unreliable on
        // create. sync() below is idempotent and cheap at this scale.
        $this->syncInstallmentSystems($machine);

        if (! $machine->wasChanged('colors')) {
            return;
        }

        MotorcycleImage::where('machine_id', $machine->id)->delete();

        $rows = [];
        $now = now();

        foreach ((array) ($machine->colors ?? []) as $colorEntry) {
            if (! is_array($colorEntry)) {
                continue;
            }

            // an explicit name from the dashboard beats guessing one from the hex
            $color = ($colorEntry['color_name'] ?? null) ?: ($colorEntry['color'] ?? null);

            if (! empty($colorEntry['color_display'])) {
                $rows[] = [
                    'machine_id' => $machine->id,
                    'color' => $color,
                    'path' => $colorEntry['color_display'],
                    'is_display' => true,
                    'sort' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_values((array) ($colorEntry['images'] ?? [])) as $sort => $path) {
                if (! $path) {
                    continue;
                }

                $rows[] = [
                    'machine_id' => $machine->id,
                    'color' => $color,
                    'path' => $path,
                    'is_display' => false,
                    'sort' => $sort,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            MotorcycleImage::insert($rows);
        }
    }

    public function deleted(Machine $machine): void
    {
        CatalogService::invalidateIndexCache();
    }

    /**
     * Projects the `installment_systems` JSON (unchanged website-facing
     * column) onto the machine_installment_system pivot (T12), same
     * resync-on-save pattern as motorcycle_images above.
     */
    private function syncInstallmentSystems(Machine $machine): void
    {
        $validSystemIds = DB::table('installment_systems')->pluck('id')->all();

        $ids = collect((array) ($machine->installment_systems ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => in_array($id, $validSystemIds, true))
            ->unique()
            ->values();

        $machine->installmentSystems()->sync($ids);
    }
}
