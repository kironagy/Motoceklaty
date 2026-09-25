<?php

namespace App\Domain\Handoff;

use App\Models\Handoff;
use App\Models\Notification;
use App\Models\Staff;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\DB;

/**
 * Gives/returns a conversation to staff. No reply wording lives here
 * (plan constraint) - only state transitions and bookkeeping.
 */
class HandoffService
{
    public function handOff(WhatsappConversation $conversation, string $reason, string $note, string $source): Handoff
    {
        $conversation->status = 'awaiting_agent';
        $conversation->save();

        $handoff = Handoff::create([
            'conversation_id' => $conversation->id,
            'reason' => $reason,
            'note' => $note,
            'source' => $source,
            'opened_at' => now(),
        ]);

        $this->notifyStaff($conversation, $handoff);

        return $handoff;
    }

    /**
     * Deterministic trigger for T17: repeated turn failures reach
     * config('agent.handoff.max_failed_turns') (DEC-13) and the customer
     * is handed off without the AI having "decided" anything from text.
     */
    public function handOffForFailures(WhatsappConversation $conversation, string $code): Handoff
    {
        return $this->handOff(
            $conversation,
            reason: 'low_confidence',
            note: "Automatic handoff after repeated turn failures: {$code}",
            source: 'system',
        );
    }

    /**
     * @return array{turn_created: bool, turn_id?: int}
     */
    public function close(WhatsappConversation $conversation, ?Staff $staff = null): array
    {
        return DB::transaction(function () use ($conversation, $staff) {
            $conversation->status = 'open';
            $conversation->save();

            Handoff::where('conversation_id', $conversation->id)
                ->whereNull('closed_at')
                ->latest('opened_at')
                ->first()
                ?->update(['closed_at' => now(), 'closed_by' => $staff?->id]);

            $lastOutbound = $conversation->messages()->where('direction', 'outgoing')->latest('id')->first();

            $pending = $conversation->messages()
                ->where('direction', 'incoming')
                ->whereNull('turn_id')
                ->when($lastOutbound, fn ($q) => $q->where('id', '>', $lastOutbound->id))
                ->orderBy('id')
                ->get();

            if ($pending->isEmpty()) {
                return ['turn_created' => false];
            }

            $first = $pending->first();
            $customer = $conversation->customer;
            $jid = $customer?->jid ?? $conversation->phone;

            $turnId = DB::table('whatsapp_message_jobs')->insertGetId([
                'whatsapp_bot_id' => $first->whatsapp_bot_id,
                'whatsapp_conversation_id' => $conversation->id,
                'from' => $jid,
                'reply_jid' => $jid,
                'message' => $first->text ?: '[media]',
                'status' => 'pending',
                'attempts' => 0,
                'first_message_at' => now(),
                // Staff already reviewed the conversation while closing the
                // handoff - process this immediately, no debounce needed.
                'process_after' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            WhatsappMessage::whereIn('id', $pending->pluck('id'))->update(['turn_id' => $turnId]);

            return ['turn_created' => true, 'turn_id' => $turnId];
        });
    }

    /**
     * Handoffs nobody on staff answered within the configured window go
     * back to the bot, so a customer who asked for a person at 2am is not
     * left in silence until morning. Runs from the scheduler.
     *
     * @return int number of conversations returned
     */
    public function returnUnansweredToAgent(): int
    {
        $minutes = config('agent.handoff.return_to_agent_after_minutes');

        if ($minutes === null || $minutes === '') {
            return 0;
        }

        $returned = 0;

        Handoff::whereNull('closed_at')
            ->where('opened_at', '<=', now()->subMinutes((int) $minutes))
            ->with('conversation')
            ->get()
            ->each(function (Handoff $handoff) use (&$returned) {
                $conversation = $handoff->conversation;

                if (! $conversation || $conversation->status !== 'awaiting_agent') {
                    return;
                }

                $staffReplied = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
                    ->whereIn('sender_type', ['agent', 'human_phone'])
                    ->where('created_at', '>=', $handoff->opened_at)
                    ->exists();

                if ($staffReplied) {
                    return;
                }

                $this->close($conversation);
                $returned++;
            });

        return $returned;
    }

    private function notifyStaff(WhatsappConversation $conversation, Handoff $handoff): void
    {
        $recipients = Staff::where('is_admin', true)->orWhere('is_super_admin', true)->get();

        foreach ($recipients as $staff) {
            Notification::create([
                'user_id' => $staff->id,
                'title' => 'تحويل محادثة لموظف',
                'message' => "محادثة {$conversation->phone} محتاجة رد موظف - سبب: {$handoff->reason}",
                'type' => 'handoff',
                'is_read' => false,
            ]);
        }
    }
}
