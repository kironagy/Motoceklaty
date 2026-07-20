<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\Brand;
use App\Models\InstallmentSystem;
use Illuminate\Http\Request;

class MachineController extends Controller
{
    public function index(Request $request)
    {
        // ✅ جلب كل الماركات مع المكن
        $brands = Brand::with('machines')->get();

        // ✅ لو فيه brand_id في الرابط (يعني المستخدم اختار ماركة معينة)
        if ($request->has('brand_id')) {
            $brandId = $request->brand_id;
            $brands = $brands->where('id', $brandId); // فلترة الماركات
            $machines = Machine::where('brand_id', $brandId)
                ->with('brand')
                ->latest()
                ->paginate(12);
        } else {
            // ✅ لو مفيش فلترة، اعرض الكل عادي
            $machines = Machine::with('brand')->latest()->paginate(12);
        }

        return view('machines.index', compact('brands', 'machines'));
    }

public function show($id)
{
    // ✅ جلب المكنة مع الماركة
    $machine = Machine::with('brand')->findOrFail($id);

    // ✅ فكّ المميزات (لو متخزنة JSON)
    $features = $machine->features ?? [];
    if (is_string($features)) {
        $features = json_decode($features, true);
    }
    if (is_array($features)) {
        $features = collect($features)
            ->map(fn($f) => is_array($f) ? implode(' ', $f) : $f)
            ->filter(fn($f) => !empty($f))
            ->values()
            ->toArray();
    }
    $machine->features = $features;

    // ✅ فكّ الألوان (لو متخزنة JSON)
    $colors = $machine->colors ?? [];
    if (is_string($colors)) {
        $colors = json_decode($colors, true);
    }
    $machine->colors = is_array($colors) ? $colors : [];

    // ✅ مكن مشابه حسب الماركة
    $relatedMachines = Machine::where('brand_id', $machine->brand_id)
        ->where('id', '!=', $machine->id)
        ->take(4)
        ->get();

    // ✅ جلب جميع البيانات المطلوبة لقسم "احسب قسط مكنتك"
    $brands = Brand::all();
    $allMachines = Machine::all();
    $installmentSystems = InstallmentSystem::all();

    // ✅ إعداد الخطط لكل نظام تقسيط
    $plans = [];
    foreach ($installmentSystems as $system) {
        $plans[$system->name] = is_string($system->plans)
            ? json_decode($system->plans, true)
            : (is_array($system->plans) ? $system->plans : []);
    }

    // ✅ تمرير كل البيانات للـ View
    return view('machines.show', compact(
        'machine',
        'relatedMachines',
        'plans',
        'brands',
        'allMachines',
        'installmentSystems'
    ));
}

}
