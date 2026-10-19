<?php

namespace App\Services;

use App\Models\SmsLog;
use Illuminate\Support\Facades\Http;

/**
 * Sends one SMS through ZADX (POST /sms/send, X-Api-Key + X-Api-Secret, Idempotency-Key required)
 * and logs it in sms_logs. Not configured or switched off: nothing leaves, the message is logged as skipped.
 * ZADX appends the app name as the last line and never retries; a timeout stays pending on their side.
 */
class SmsService
{
    public function send(?string $phone, string $message, ?int $requestId = null, ?string $idempotencyKey = null): SmsLog
    {
        $phone = static::normalizePhone($phone);
        $config = config('services.sms');

        $log = SmsLog::create([
            'installment_request_id' => $requestId,
            'phone' => $phone,
            'message' => $message,
            'status' => 'skipped',
        ]);

        if (! $phone) {
            $log->update(['response' => 'مفيش رقم موبايل صالح']);

            return $log;
        }

        if (! $config['enabled'] || blank($config['url']) || blank($config['key']) || blank($config['secret'])) {
            $log->update(['response' => 'الـ SMS مش متفعل (SMS_ENABLED / SMS_API_KEY / SMS_API_SECRET)']);

            return $log;
        }

        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->withHeaders([
                    'X-Api-Key' => $config['key'],
                    'X-Api-Secret' => $config['secret'],
                    // Same key + same body never sends twice (ZADX keeps it 24h).
                    'Idempotency-Key' => $idempotencyKey ?? 'sms-'.$log->id,
                ])
                ->post($config['url'], array_filter([
                    'to' => '0'.substr($phone, 2),
                    'message' => $message,
                    'sender_id' => $config['sender'],
                ]));

            $log->update([
                'status' => $response->successful() ? 'sent' : 'failed',
                'response' => mb_substr($response->status().' '.$response->body(), 0, 2000),
            ]);
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'response' => mb_substr($e->getMessage(), 0, 2000)]);
        }

        return $log;
    }

    /** Egyptian mobile in international form: 201xxxxxxxxx; anything else is null. */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', strtr((string) $phone, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));

        if (preg_match('/^(?:0020|20|0)?(1[0125]\d{8})$/', $digits, $m)) {
            return '20'.$m[1];
        }

        return null;
    }
}
