<?php

namespace App\Http\Controllers;

use App\Models\TeamMember;

class TeamController extends Controller
{
    public function index()
    {
        $members = TeamMember::visible()->get();

        return view('site.team.index', compact('members'));
    }
}
