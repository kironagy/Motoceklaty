<?php

namespace App\Http\Controllers;

use App\Models\InstallmentSystem;
use App\Models\Machine;
use Illuminate\Http\Request;

class ApplyInstallmentController extends Controller
{
    public function index(Request $request)
    {
        $machines = Machine::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        // Keyed by system name: InstallmentRequestController stores installment_type as the name.
        $plans = InstallmentSystem::query()
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->get(['name', 'plans'])
            ->mapWithKeys(fn ($system) => [
                $system->name => is_array($system->plans) ? $system->plans : (json_decode($system->plans, true) ?? []),
            ])
            ->toArray();

        $selectedMachineId = $request->integer('machine_id') ?: null;

        return view('site.installments.apply', compact('machines', 'plans', 'selectedMachineId'));
    }
}
