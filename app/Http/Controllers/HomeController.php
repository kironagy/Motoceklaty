<?php

namespace App\Http\Controllers;

use App\Models\Slider;
use App\Models\Brand;
use App\Models\Machine;
use App\Models\InstallmentSystem;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index()
    {
        $sliders = Slider::latest()->get();
        $brands = Brand::all();
        $installmentSystems = InstallmentSystem::all();

        // ✅ المكن العادي (بدون عروض)
        $machines = Machine::where('type', 'normal')
            ->latest()
            ->take(8)
            ->get()
            ->map(function ($machine) {
                $features = $machine->features ?? [];
                if (is_string($features)) $features = json_decode($features, true);
                if (is_array($features)) {
                    $features = collect($features)
                        ->map(fn($f) => is_array($f) ? implode(' ', $f) : $f)
                        ->filter(fn($f) => !empty($f))
                        ->values()
                        ->toArray();
                }
                $machine->features = $features;
                return $machine;
            });

        // ✅ المكن العروض الخاصة (offer)
        $offerMachines = Machine::where('type', 'offer')
            ->latest()
            ->get()
            ->map(function ($machine) {
                $features = $machine->features ?? [];
                if (is_string($features)) $features = json_decode($features, true);
                if (is_array($features)) {
                    $features = collect($features)
                        ->map(fn($f) => is_array($f) ? implode(' ', $f) : $f)
                        ->filter(fn($f) => !empty($f))
                        ->values()
                        ->toArray();
                }
                $machine->features = $features;
                return $machine;
            });

        // ✅ كل المكن (العادي + العروض)
        $allMachines = Machine::latest()->get();

        // ✅ نقسم العروض لمجموعات (كل مجموعة فيها عرضين)
        $offerChunks = $offerMachines->chunk(2);

        // ✅ الإعلانات
        $ads = \App\Models\Ad::latest()->pluck('title')->toArray();
        if (count($ads) < 10 && count($ads) > 0) {
            $repeatTimes = ceil(10 / count($ads));
            $ads = array_merge(...array_fill(0, $repeatTimes, $ads));
        }

        // ✅ الشركاء
        $partners = \App\Models\Partner::latest()->pluck('image')->toArray();
        if (count($partners) < 6 && count($partners) > 0) {
            $repeatTimes = ceil(6 / count($partners));
            $partners = array_merge(...array_fill(0, $repeatTimes, $partners));
        }

        return view('home', compact(
            'sliders',
            'brands',
            'machines',
            'installmentSystems',
            'ads',
            'partners',
            'offerChunks',
            'allMachines' // ✅ الفورم هتستخدم ده
        ));
    }
}
