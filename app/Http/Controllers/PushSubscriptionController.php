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

    /** Owner 2026-11-27: a test push to this device only, and what the push service answered. */
    public function test(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $endpoint = (string) $request->input('endpoint');
        $subscription = PushSubscription::where('user_id', $user->getAuthIdentifier())->where('endpoint', $endpoint)->first();

        if (! $subscription) {
            return response()->json(['ok' => false, 'reason' => 'not_subscribed']);
        }

        $webPush = new \Minishlink\WebPush\WebPush(['VAPID' => [
            'subject' => config('services.web_push.subject'),
            'publicKey' => config('services.web_push.public_key'),
            'privateKey' => config('services.web_push.private_key'),
        ]]);
        $webPush->queueNotification(\Minishlink\WebPush\Subscription::create([
            'endpoint' => $subscription->endpoint,
            'publicKey' => $subscription->public_key,
            'authToken' => $subscription->auth_token,
            'contentEncoding' => $subscription->content_encoding ?: 'aes128gcm',
        ]), json_encode(['title' => 'Moto Gate: تجربة إشعار', 'body' => 'لو شايف ده يبقى الإشعارات شغالة على الجهاز ده.', 'url' => '/admin/notifications', 'tag' => 'push-test-'.now()->timestamp], JSON_UNESCAPED_UNICODE));

        foreach ($webPush->flush() as $report) {
            return response()->json(['ok' => $report->isSuccess(), 'status' => $report->getResponse()?->getStatusCode(), 'reason' => $report->isSuccess() ? null : mb_substr((string) $report->getReason(), 0, 200)]);
        }

        return response()->json(['ok' => false, 'reason' => 'no_report']);
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
