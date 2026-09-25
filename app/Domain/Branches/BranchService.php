<?php

namespace App\Domain\Branches;

use App\Models\Branch;

class BranchService
{
    /**
     * @return array{branches: array, all_governorates_with_branches?: string[]}
     */
    public function find(?string $governorate, ?string $city): array
    {
        $query = Branch::query()->where('is_active', true);

        if ($governorate !== null) {
            $query->where('governorate', $governorate);
        }

        if ($city !== null) {
            $query->where('city', 'like', '%'.$city.'%');
        }

        $branches = $query->orderBy('sort')->get();

        if ($branches->isEmpty() && $governorate !== null) {
            return [
                'branches' => [],
                'all_governorates_with_branches' => Branch::where('is_active', true)
                    ->distinct()
                    ->pluck('governorate')
                    ->values()
                    ->all(),
            ];
        }

        return [
            'branches' => $branches->map(fn (Branch $b) => [
                'name' => $b->name,
                'governorate' => $b->governorate,
                'city' => $b->city,
                'address' => $b->address,
                'map_url' => $b->map_url,
                'phones' => $b->phones ?? [],
                'working_hours' => $b->working_hours ?? [],
                'services' => $b->services ?? [],
            ])->values()->all(),
        ];
    }
}
