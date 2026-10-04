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
        public ?string $customerAction = null,
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

        // Staff moved on since this was queued: the newer status sends its own.
        if ((string) $request->status !== $this->status
            || ($this->customerAction !== null && (string) $request->customer_action !== $this->customerAction)) {
            return;
        }
        // His chat's number may be logged out: send from a connected one.
        $conversation = app(\App\Domain\Conversations\BotSessions::class)->liveConversation($request->whatsappConversation);

        if (! $conversation) {
            Log::warning('SendWhatsappStatusNotification: no WhatsApp number connected', ['installment_request_id' => $request->id]);
            $this->release(300);

            return;
        }

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

    /**
     * The chat he talks to the bot on - the same jid the bot replies to.
     * Owner 2026-10-04: never the phone typed in the form (request 4449's
     * decision went to the form number, not his chat).
     */
    private function recipientJid(InstallmentRequest $request, object $conversation): ?string
    {
        $jid = trim((string) ($conversation->customer?->jid ?? ''));

        if ($jid !== '' && str_contains($jid, '@')) {
            return $jid;
        }

        $phone = trim((string) $conversation->phone);

        if ($phone === '') {
            return null;
        }

        if (str_contains($phone, '@')) {
            return $phone;
        }

        // A numeric LID stored without its suffix.
        return $phone.(preg_match('/^\d{14,15}$/', $phone) && ! str_starts_with($phone, '20') ? '@lid' : '@s.whatsapp.net');
    }

    /** Worded per status and per what staff need from the customer. */
    private function messageFor(InstallmentRequest $request, string $status, ?string $reason): ?string
    {
        return app(\App\Domain\Applications\StaffDecisionService::class)->message($request, $status, $reason);
    }
}
