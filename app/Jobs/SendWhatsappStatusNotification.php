<?php

namespace App\Jobs;

use App\Domain\Conversations\DeliveryService;
use App\Models\InstallmentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

    public function handle(DeliveryService $delivery): void
    {
        $request = InstallmentRequest::with('whatsappConversation')->find($this->installmentRequestId);

        if (! $request || ! $request->whatsappConversation) {
            Log::warning('SendWhatsappStatusNotification: no conversation to notify', [
                'installment_request_id' => $this->installmentRequestId,
            ]);

            return;
        }

        $conversation = $request->whatsappConversation;
        $jid = $this->recipientJid($request, $conversation);

        if (! $jid) {
            Log::warning('SendWhatsappStatusNotification: no valid recipient JID', [
                'installment_request_id' => $request->id,
                'conversation_phone' => $conversation->phone,
                'applicant_phone' => $request->applicant_phone,
            ]);

            return;
        }

        $message = $this->messageFor($request, $this->status, $this->reason);

        if (! $message) {
            return;
        }

        // T14: routed through DeliveryService so this is persisted as a
        // real (system) outbound whatsapp_messages row, same as every
        // other outbound message - but not gated on agent.enabled, since
        // this notification predates the agent and must keep firing
        // regardless of that master switch.
        $delivery->deliverForConversation(
            $conversation,
            ['messages' => [$message]],
            'system',
            $jid,
            requireAgentEnabled: false,
        );

        Log::info('WHATSAPP STATUS NOTIFICATION SENT', [
            'installment_request_id' => $request->id,
            'status' => $this->status,
            'jid' => $jid,
            'conversation_phone' => $conversation->phone,
        ]);
    }

    private function recipientJid(InstallmentRequest $request, object $conversation): ?string
    {
        $conversationPhone = trim((string) $conversation->phone);

        if ($conversationPhone === '') {
            return null;
        }

        // Keep an explicitly stored JID intact, including WhatsApp LID values.
        if (str_contains($conversationPhone, '@')) {
            return $conversationPhone;
        }

        // Older conversations stored a numeric LID without its @lid suffix.
        // In that case, use the customer's registered Egyptian phone number.
        if (preg_match('/^\d{15}$/', $conversationPhone)) {
            $phone = preg_replace('/\D+/', '', (string) $request->applicant_phone);

            if (preg_match('/^01[0125]\d{8}$/', $phone)) {
                return '20' . substr($phone, 1) . '@s.whatsapp.net';
            }

            return null;
        }

        return $conversationPhone . '@s.whatsapp.net';
    }

    private function messageFor(InstallmentRequest $request, string $status, ?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return match ($status) {
            'approved' => "ألف مبروك يا فندم، طلب التقسيط رقم #{$request->id} تمت الموافقة عليه.\nبرجاء التوجه إلى الفرع لاستكمال باقي الإجراءات واستلام المكنة.",
            'paused' => "طلبك رقم #{$request->id} متوقف مؤقتًا."
                . ($reason !== '' ? "\nالسبب: {$reason}" : '')
                . "\nبرجاء التواصل مع المعرض لاستكمال المطلوب.",
            'rejected' => "للأسف طلبك رقم #{$request->id} اترفض."
                . ($reason !== '' ? "\nالسبب: {$reason}" : ''),
            default => null,
        };
    }
}
