<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $this->currentUser();

        $data = $request->validate([
            'endpoint' => ['required', 'url'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'contentEncoding' => ['nullable', 'string'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'user_id' => $user->getAuthIdentifier(),
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
            ]
        );

        return response()->json([
            'success' => true,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $this->currentUser();

        PushSubscription::where('user_id', $user->getAuthIdentifier())
            ->where('endpoint', $request->input('endpoint'))
            ->delete();

        return response()->json([
            'success' => true,
        ]);
    }

    private function currentUser(): Authenticatable
    {
        foreach (array_keys(config('auth.guards')) as $guard) {
            if (Auth::guard($guard)->check()) {
                return Auth::guard($guard)->user();
            }
        }

        abort(401, 'Unauthenticated');
    }
}
