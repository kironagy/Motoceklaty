<?php

namespace App\Jobs;

use App\Models\InstallmentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendWhatsappStatusNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $installmentRequestId,
        public string $status,
        public ?string $reason = null,
    ) {
    }

    public function handle(): void
    {
        $request = InstallmentRequest::with('whatsappConversation')->find($this->installmentRequestId);

        if (! $request || ! $request->whatsappConversation) {
            Log::warning('SendWhatsappStatusNotification: no conversation to notify', [
                'installment_request_id' => $this->installmentRequestId,
            ]);

            return;
        }

        $conversation = $request->whatsappConversation;
        $jid = $conversation->phone . '@s.whatsapp.net';

        $message = $this->messageFor($request, $this->status, $this->reason);

        if (! $message) {
            return;
        }

        $url = rtrim(env('WHATSAPP_WORKER_URL', 'http://127.0.0.1:3010'), '/') . '/send-message';

        $response = Http::connectTimeout(10)
            ->timeout(60)
            ->withHeaders([
                'X-BOT-TOKEN' => config('services.whatsapp.bot_token'),
                'Accept' => 'application/json',
            ])
            ->post($url, [
                'bot_id' => (string) $conversation->whatsapp_bot_id,
                'jid' => $jid,
                'message' => $message,
            ]);

        Log::info('WHATSAPP STATUS NOTIFICATION SENT', [
            'installment_request_id' => $request->id,
            'status' => $this->status,
            'jid' => $jid,
            'response_status' => $response->status(),
            'response_ok' => $response->json('ok'),
        ]);

        if (! $response->successful() || ! $response->json('ok')) {
            throw new \RuntimeException(
                'WhatsApp status notification failed: ' . $response->status() . ' - ' . $response->body()
            );
        }
    }

    private function messageFor(InstallmentRequest $request, string $status, ?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return match ($status) {
            'approved' => "تمام يا فندم، طلبك رقم #{$request->id} تمت الموافقة عليه.\nتعالى الفرع علشان تكمل باقي الإجراءات.",
            'paused' => "طلبك رقم #{$request->id} متوقف مؤقتًا."
                . ($reason !== '' ? "\nالسبب: {$reason}" : '')
                . "\nبرجاء التواصل مع المعرض لاستكمال المطلوب.",
            'rejected' => "للأسف طلبك رقم #{$request->id} اترفض."
                . ($reason !== '' ? "\nالسبب: {$reason}" : ''),
            default => null,
        };
    }
}
