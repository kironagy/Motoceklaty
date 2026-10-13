<?php

namespace App\Http\Controllers;

/** Brands live as filter chips on the showroom grid now. */
class BrandController extends Controller
{
    public function index()
    {
        return redirect()->route('machines.index');
    }

    public function show($id)
    {
        return redirect()->route('machines.index', ['brand_id' => $id]);
    }
}
