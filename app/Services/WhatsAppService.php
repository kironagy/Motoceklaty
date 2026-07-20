<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WhatsAppService
{
    public function sendText(string $to, string $message, ?string $phoneNumberId = null): array
    {
        $phoneNumberId = $phoneNumberId ?: env('WHATSAPP_PHONE_NUMBER_ID');
        $accessToken = env('WHATSAPP_ACCESS_TOKEN');
        $version = env('WHATSAPP_API_VERSION', 'v23.0');

        $url = "https://graph.facebook.com/{$version}/{$phoneNumberId}/messages";

        $response = Http::withToken($accessToken)
            ->post($url, [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => [
                    'body' => $message,
                ],
            ]);

        if ($response->failed()) {
            throw new \Exception('WhatsApp send failed: ' . $response->body());
        }

        return $response->json();
    }
}