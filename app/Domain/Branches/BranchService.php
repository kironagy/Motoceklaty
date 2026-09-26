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

        // "انا في المنصورة" with no branch there got an invented Mansoura
        // branch. The customer gets every real branch to choose the nearest.
        if ($branches->isEmpty() && ($governorate !== null || $city !== null)) {
            return [
                'branches' => $this->present(Branch::where('is_active', true)->orderBy('sort')->get()),
                'no_branch_in_requested_area' => true,
                'note' => 'We have NO branch in '.($governorate ? (config('agent.governorates')[$governorate] ?? $governorate) : $city)
                    .'. Say so plainly, then give the branches listed here (the nearest to him first). Never name any other branch or area.',
            ];
        }

        return ['branches' => $this->present($branches)];
    }

    private function present($branches): array
    {
        return $branches->map(fn (Branch $b) => [
                'name' => $b->name,
                'governorate' => $b->governorate,
                'city' => $b->city,
                'address' => $b->address,
                'map_url' => $b->map_url,
                'phones' => $b->phones ?? [],
                'working_hours' => $b->working_hours ?? [],
                'services' => $b->services ?? [],
                'governorate_name' => config('agent.governorates')[$b->governorate] ?? $b->governorate,
            ])->values()->all();
    }
}
