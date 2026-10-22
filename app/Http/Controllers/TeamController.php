<?php

namespace App\Http\Controllers;


class TeamController extends Controller
{
    /** The approved design has no team page: the team lives on the about page. */
    public function index()
    {
        return redirect()->to(route('about.index') . '#team', 301);
    }
}
