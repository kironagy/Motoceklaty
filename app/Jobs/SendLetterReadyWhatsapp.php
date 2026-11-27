<?php

namespace App\Jobs;

use App\Domain\Conversations\BotSessions;
use App\Domain\Conversations\DeliveryService;
use App\Models\Customer;
use App\Models\Letter;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
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

    public function handle(DeliveryService $delivery, BotSessions $sessions): void
    {
        $letter = Letter::find($this->letterId);

        if (! $letter || $letter->status !== self::READY_STATUS || blank($letter->letter_image) || $letter->ready_sent_at) {
            return;
        }

        $digits = preg_replace('/\D/', '', (string) $letter->phone);
        $phone = str_starts_with($digits, '0') ? '2'.$digits : $digits;

        if (! preg_match('/^201\d{9}$/', $phone)) {
            Log::warning('SendLetterReadyWhatsapp: no valid phone', ['letter_id' => $letter->id]);

            return;
        }

        $conversation = $this->conversation($phone, $sessions);

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

    /** His chat on a connected number when he has one; otherwise a new chat on a connected number. */
    private function conversation(string $phone, BotSessions $sessions): ?WhatsappConversation
    {
        $live = WhatsappBot::query()->where('is_active', true)->whereNull('archived_at')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $connected = $sessions->connected();
        $bots = $connected === null ? $live : array_values(array_intersect($connected, $live));

        if ($bots === []) {
            return null;
        }

        $existing = WhatsappConversation::where('phone', $phone)->whereIn('whatsapp_bot_id', $bots)->latest('id')->first();

        if ($existing) {
            return $existing;
        }

        $customer = Customer::firstOrCreate(['whatsapp_bot_id' => $bots[0], 'jid' => $phone.'@s.whatsapp.net'], ['phone' => $phone]);

        return WhatsappConversation::firstOrCreate(
            ['whatsapp_bot_id' => $bots[0], 'phone' => $phone],
            ['status' => 'open', 'customer_id' => $customer->id],
        );
    }
}
