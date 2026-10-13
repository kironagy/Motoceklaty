<?php

namespace App\Http\Controllers;

use App\Models\NewsletterSubscriber;
use Illuminate\Http\Request;

class NewsletterController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validateWithBag('newsletter', [
            'email' => ['required', 'email', 'max:150'],
        ]);

        NewsletterSubscriber::firstOrCreate(['email' => mb_strtolower(trim($data['email']))]);

        return redirect(url()->previous() . '#newsletter')->with('newsletter_status', site('general.footer.newsletter_success'));
    }
}
