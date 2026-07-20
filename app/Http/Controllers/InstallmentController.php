<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Machine;
use App\Models\InstallmentSystem;

class InstallmentController extends Controller
{
    public function index()
    {
        $brands = Brand::all();
        $machines = Machine::all();
        $installmentSystems = InstallmentSystem::all();

        return view('installments.index', compact('brands', 'machines', 'installmentSystems'));
    }
}
