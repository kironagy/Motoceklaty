<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\InstallmentSystem;

class ApplyInstallmentController extends Controller
{
    public function index()
    {
        // هنجلب كل المكن علشان المستخدم يختار اللي هيقدّم عليه
        $machines = Machine::select('id', 'name')->get();

        // أنظمة التقسيط
        $plans = InstallmentSystem::select('name', 'plans')
            ->get()
            ->mapWithKeys(function ($item) {
                $decoded = is_array($item->plans)
                    ? $item->plans
                    : json_decode($item->plans, true);

                return [$item->name => $decoded ?? []];
            })
            ->toArray();

        return view('installments.apply-form', compact('machines', 'plans'));
    }
}
