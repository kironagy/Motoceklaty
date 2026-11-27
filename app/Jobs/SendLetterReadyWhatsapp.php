<?php

namespace App\Jobs;

use App\Domain\Conversations\DeliveryService;
use App\Models\Letter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Owner 2026-11-27: a letter set «تم التنفيذ» with its «صورة الجواب» - the photo goes to the
 * customer on WhatsApp («جوابك جاهز...»), with what is left to pay when something is.
 * From any connected number (the owner's choice).
 */
class SendLetterReadyWhatsapp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const READY_STATUS = 'تم التنفيذ';

    public int $timeout = 600;

    public int $backoff = 60;

    public int $maxExceptions = 5;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function __construct(public int $letterId)
    {
    }

    public static function message(Letter $letter): string
    {
        $name = trim(explode(' ', trim((string) $letter->customer_name))[0] ?? '');
        $text = 'أهلاً'.($name !== '' ? ' يا '.$name : '')."،\nجوابك جاهز، تقدر تيجي تستلمه دلوقتي.";
        $remaining = (float) $letter->remaining;

        if ($remaining > 0) {
            $text .= "\nجهّز معاك مبلغ ".number_format($remaining, 0, '.', ',').' جنيه (الباقي) وإنت جاي تستلم جوابك.';
        }

        return $text;
    }

    public function handle(DeliveryService $delivery): void
    {
        $letter = Letter::find($this->letterId);

        if (! $letter || $letter->status !== self::READY_STATUS || blank($letter->letter_image) || $letter->ready_sent_at) {
            return;
        }

        $phone = \App\Domain\Conversations\DirectWhatsapp::phone($letter->phone);

        if (! $phone) {
            Log::warning('SendLetterReadyWhatsapp: no valid phone', ['letter_id' => $letter->id]);

            return;
        }

        $conversation = \App\Domain\Conversations\DirectWhatsapp::conversation($phone);

        if (! $conversation) {
            Log::warning('SendLetterReadyWhatsapp: no WhatsApp number connected', ['letter_id' => $letter->id]);
            $this->release(300);

            return;
        }

        $path = ltrim((string) $letter->letter_image, '/');
        $delivery->deliverForConversation(
            $conversation,
            ['media' => [[
                'url' => asset('storage/'.$path),
                'type' => 'image',
                'mime' => 'image/'.(strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'png' ? 'png' : 'jpeg'),
                'filename' => basename($path),
                'path' => $path,
                'caption' => self::message($letter),
            ]]],
            'system',
            requireAgentEnabled: false,
            dedupeKey: 'letter-ready:'.$letter->id,
        );

        $letter->forceFill(['ready_sent_at' => now()])->saveQuietly();
        Log::info('LETTER READY SENT', ['letter_id' => $letter->id, 'bot_id' => $conversation->whatsapp_bot_id]);
    }
}
