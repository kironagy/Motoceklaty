<?php

namespace App\Jobs;

use App\Domain\Conversations\DeliveryService;
use App\Models\Handoff;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * A customer writing while their conversation waits for staff used to get
 * nothing back. Sends the configured waiting line - only when staff have not
 * replied since the handoff opened, and at most once per interval.
 */
class AcknowledgeHandoffWait implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $conversationId)
    {
    }

    public function handle(DeliveryService $delivery): void
    {
        $message = config('agent.handoff.waiting_message');
        $conversation = WhatsappConversation::find($this->conversationId);

        if (blank($message) || ! $conversation || $conversation->status !== 'awaiting_agent') {
            return;
        }

        $handoff = Handoff::where('conversation_id', $conversation->id)->whereNull('closed_at')->latest('opened_at')->first();

        if (! $handoff) {
            return;
        }

        $since = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('created_at', '>=', $handoff->opened_at);

        if ((clone $since)->whereIn('sender_type', ['agent', 'human_phone'])->exists()) {
            return; // a person is already talking to them
        }

        $interval = (int) config('agent.handoff.waiting_ack_interval_minutes');
        $recentAck = (clone $since)->where('sender_type', 'system')
            ->where('created_at', '>=', now()->subMinutes($interval))
            ->exists();

        if ($recentAck) {
            return;
        }

        try {
            $delivery->deliverForConversation($conversation, ['messages' => [$message]], senderType: 'system');
        } catch (\Throwable $e) {
            // Best effort: a failed acknowledgement must never break ingestion.
            Log::warning('Handoff wait acknowledgement failed', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);
        }
    }
}
