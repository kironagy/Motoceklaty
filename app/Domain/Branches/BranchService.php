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

        // "البساتين" (Cairo) matched no branch city and the note said "no
        // branch in القاهرة" - the bot then offered Giza while Cairo has
        // two. No branch in his area: the ones in his governorate come first.
        if ($branches->isEmpty() && $city !== null && $governorate !== null) {
            $inGovernorate = Branch::where('is_active', true)->where('governorate', $governorate)->orderBy('sort')->get();

            if ($inGovernorate->isNotEmpty()) {
                return [
                    'branches' => $this->present($inGovernorate),
                    'no_branch_in_requested_area' => true,
                    'note' => 'No branch in '.$city.' itself. These are our branches in '.(config('agent.governorates')[$governorate] ?? $governorate)
                        .', his governorate - give them as the nearest. Never name any other branch or area.',
                ];
            }
        }

        // "انا في المنصورة" with no branch there got an invented Mansoura
        // branch. The customer gets every real branch, nearest first - left
        // to the model, Mansoura got Cairo and Giza before الخصوص.
        if ($branches->isEmpty() && ($governorate !== null || $city !== null)) {
            $all = $this->nearestFirst(Branch::where('is_active', true)->orderBy('sort')->get(), $governorate);
            $nearest = $governorate !== null && isset(config('agent.governorate_coordinates')[$governorate]) ? $all->first() : null;
            $place = $city ?: (config('agent.governorates')[$governorate] ?? $governorate);

            return [
                'branches' => $this->present($all),
                'no_branch_in_requested_area' => true,
            ] + ($nearest ? ['nearest_branch' => $nearest->name] : []) + [
                'note' => 'No branch in '.$place.'. Say it in one short line using the place as HE said it (e.g. "المنصورة", not the governorate\'s formal name)'
                    .($nearest ? ', then give the nearest one first: '.$nearest->name.' (address, hours, map link from this result)' : ', then give the branches listed here')
                    .'. If he wants to buy, tell him he can buy there. Never name any other branch or area.',
            ];
        }

        return ['branches' => $this->present($branches)];
    }

    private function nearestFirst($branches, ?string $governorate)
    {
        $coordinates = config('agent.governorate_coordinates', []);
        $from = $governorate !== null ? ($coordinates[$governorate] ?? null) : null;

        if (! $from) {
            return $branches;
        }

        return $branches->sortBy(function (Branch $branch) use ($coordinates, $from) {
            [$lat, $lng] = $coordinates[$branch->governorate] ?? [0, 0];

            return ($lat - $from[0]) ** 2 + (($lng - $from[1]) * cos(deg2rad($from[0]))) ** 2;
        })->values();
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
