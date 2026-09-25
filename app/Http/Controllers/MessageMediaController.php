<?php

namespace App\Http\Controllers;

use App\Models\MessageMedia;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * DEC-18: new customer media lives on the private disk; staff view it only
 * through this authorized route, never a public URL.
 */
class MessageMediaController extends Controller
{
    public function show(MessageMedia $messageMedia): StreamedResponse
    {
        abort_unless(Storage::disk($messageMedia->disk)->exists($messageMedia->path), 404);

        return Storage::disk($messageMedia->disk)->response(
            $messageMedia->path,
            $messageMedia->original_filename,
            ['Content-Type' => $messageMedia->mime]
        );
    }
}
