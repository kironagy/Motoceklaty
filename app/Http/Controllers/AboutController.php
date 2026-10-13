<?php

namespace App\Http\Controllers;

use App\Models\ClientReview;
use App\Models\TeamMember;

class AboutController extends Controller
{
    public function index()
    {
        $team = TeamMember::visible()
            ->where('show_on_about', true)
            ->take(max(1, (int) site('about.team.limit', 4)))
            ->get();

        $review = ClientReview::visible()
            ->when(site('about.testimonial.review_id'), fn ($q, $id) => $q->whereKey($id))
            ->first() ?? ClientReview::visible()->first();

        return view('site.about.index', compact('team', 'review'));
    }
}
