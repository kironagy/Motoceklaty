<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\ContactMessage;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index()
    {
        $branches = Branch::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get();

        return view('site.contact.index', compact('branches'));
    }

    public function store(Request $request)
    {
        if (filled($request->input('website'))) {
            return redirect(route('contact') . '#contact-form')->with('contact_status', site('contact.form.success'));
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        ContactMessage::create($data);

        return redirect(route('contact') . '#contact-form')->with('contact_status', site('contact.form.success'));
    }
}
