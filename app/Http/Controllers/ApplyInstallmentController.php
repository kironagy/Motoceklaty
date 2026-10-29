<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ApplyInstallmentController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('machines.index');
    }
}
