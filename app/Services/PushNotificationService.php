<?php

namespace App\Services;

use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class PushNotificationService
{
    public function sendToUser(
        int $userId,
        string $title,
        string $body,
        ?string $url = null,
        ?string $tag = null,
    ): void {
        $subscriptions = PushSubscription::where('user_id', $userId)->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('services.web_push.subject'),
                'publicKey' => config('services.web_push.public_key'),
                'privateKey' => config('services.web_push.private_key'),
            ],
        ]);

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url ?? '/admin/notifications',
            'tag' => $tag ?? 'notification-' . now()->timestamp,
        ], JSON_UNESCAPED_UNICODE);

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->public_key,
                    'authToken' => $subscription->auth_token,
                    'contentEncoding' => $subscription->content_encoding ?: 'aes128gcm',
                ]),
                $payload
            );
        }

        foreach ($webPush->flush() as $report) {
            if (! $report->isSuccess() && in_array(
                $report->getResponse()?->getStatusCode(),
                [404, 410],
                true
            )) {
                PushSubscription::where('endpoint', (string) $report->getRequest()->getUri())
                    ->delete();
            }
        }
    }
}