<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Machine;
use App\Support\InstallmentCalculatorCatalog;
use Illuminate\Http\Request;

class MachineController extends Controller
{
    public function index(Request $request)
    {
        $categories = config('agent.catalog.categories', []);
        $category = array_key_exists($request->query('category'), $categories) ? $request->query('category') : null;
        $search = trim((string) $request->query('q', ''));
        $brandId = $request->integer('brand') ?: $request->integer('brand_id') ?: null; // brand_id: old site links

        // Everything except the brand filter - the chips count within this.
        $base = Machine::query()
            ->where('is_active', true)
            ->when($category, fn ($q) => $q->where('category', $category))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
                $q->where(fn ($q) => $q->where('name', 'like', $like)
                    ->orWhere('aliases', 'like', $like)
                    ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', $like)));
            });

        $counts = (clone $base)->selectRaw('brand_id, count(*) as total')->groupBy('brand_id')->pluck('total', 'brand_id');

        $brands = Brand::query()
            ->whereIn('id', $counts->keys())
            ->orderBy('id')
            ->get()
            ->each(fn (Brand $brand) => $brand->visible_count = (int) ($counts[$brand->id] ?? 0));

        if ($brandId && ! $brands->contains('id', $brandId)) {
            $brandId = null; // brand has nothing under the current search/category
        }

        $machines = (clone $base)
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->with('brand')
            ->orderByDesc('is_featured')
            ->latest()
            ->paginate(max(1, (int) site('showroom.filter.per_page', 12)))
            ->withQueryString()
            ->fragment('vehiclesRow');

        $activeCategory = $category ? $categories[$category] : null;
        $totalAll = (int) $counts->sum();

        return view('site.machines.index', compact('machines', 'brands', 'activeCategory', 'category', 'search', 'brandId', 'totalAll'));
    }

    public function show($id)
    {
        $machine = Machine::query()->where('is_active', true)->with('brand')->findOrFail($id);

        $related = Machine::query()
            ->where('is_active', true)
            ->whereKeyNot($machine->id)
            ->where(fn ($q) => $q->where('brand_id', $machine->brand_id)
                ->when($machine->category, fn ($q) => $q->orWhere('category', $machine->category)))
            ->with('brand')
            ->orderByRaw('brand_id = ? desc', [$machine->brand_id])
            ->latest()
            ->take(4)
            ->get();

        $hasCalculator = collect(InstallmentCalculatorCatalog::payload()['machines'])
            ->contains('id', $machine->id);

        return view('site.machines.show', compact('machine', 'related', 'hasCalculator'));
    }
}
