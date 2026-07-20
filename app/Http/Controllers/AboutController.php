<?php

namespace App\Http\Controllers;

use App\Models\ClientReview;
use Illuminate\Http\Request;

class AboutController extends Controller
{
    public function index()
    {
        $reviews = ClientReview::latest()->take(10)->get(); // آخر 10 آراء مثلًا
        return view('about/index', compact('reviews'));
    }
}
