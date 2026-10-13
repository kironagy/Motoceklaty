<?php

namespace App\Http\Controllers;

use App\Models\ClientReview;
use App\Models\Machine;
use App\Models\Post;

class HomeController extends Controller
{
    public function index()
    {
        $limit = max(1, (int) site('home.fleet.limit', 8));

        $fleet = Machine::query()
            ->where('is_active', true)
            ->with('brand')
            ->when(site('home.fleet.source') === 'offers', fn ($q) => $q->where('type', 'offer'))
            ->when(site('home.fleet.source') === 'featured', fn ($q) => $q->orderByDesc('is_featured'))
            ->latest()
            ->take($limit)
            ->get();

        $reviews = ClientReview::visible()->get();

        $posts = Post::published()->take(max(1, (int) site('home.blog.limit', 4)))->get();

        return view('site.home.index', compact('fleet', 'reviews', 'posts'));
    }
}
