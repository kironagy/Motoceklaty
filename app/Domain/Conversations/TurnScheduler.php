<?php

namespace App\Domain\Conversations;

use App\Jobs\AcknowledgeHandoffWait;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\DB;

/**
 * DEC-07: groups inbound messages into turns (one open turn per
 * conversation, debounced), and supersedes a turn that's already
 * generating when new messages arrive mid-generation. This is scheduling
 * only - it never reads message text/keywords to decide anything (plan
 * principle 2).
 */
class TurnScheduler implements TurnSchedulerHook
{
    public function onMessageIngested(WhatsappMessage $message): void
    {
        $conversation = $message->conversation;

        if ($conversation->status === 'awaiting_agent') {
            // Message stays with turn_id=null; a human is already handling it.
            if ($message->direction === 'incoming') {
                AcknowledgeHandoffWait::dispatch($conversation->id)->afterCommit();
            }

            return;
        }

        // A colleague is writing to this customer from the phone: the bot
        // answering in the same minute contradicted him (the customer got
        // two different questions at once). The customer's messages stay
        // in the history for the bot once the pause is over.
        if (self::staffActive($conversation)) {
            return;
        }

        $debounceSeconds = $message->type === 'text'
            ? (int) config('agent.turns.debounce_seconds')
            : (int) config('agent.turns.media_debounce_seconds');

        $maxWaitSeconds = (int) config('agent.turns.max_wait_seconds');

        $pending = DB::table('whatsapp_message_jobs')
            ->where('whatsapp_conversation_id', $conversation->id)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($pending && ! $this->maxWaitElapsed($pending, $maxWaitSeconds)) {
            $this->attachToTurn($message, $pending, $debounceSeconds, $maxWaitSeconds);

            return;
        }

        // A reply still being generated - or generated but not yet
        // delivered (WhatsApp down, retry pending) - is stale once the
        // customer has said something new.
        $processing = DB::table('whatsapp_message_jobs')
            ->where('whatsapp_conversation_id', $conversation->id)
            ->whereIn('status', ['processing', 'generated'])
            ->whereNull('superseded_by')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($processing) {
            $newTurnId = $this->createTurn($message, $debounceSeconds);

            DB::table('whatsapp_message_jobs')
                ->where('id', $processing->id)
                ->update(['status' => 'superseded', 'superseded_by' => $newTurnId, 'updated_at' => now()]);

            $message->update(['turn_id' => $newTurnId]);

            return;
        }

        // No open turn (none yet, or the last one is done/generated/failed/
        // superseded/skipped): start a fresh one.
        $turnId = $this->createTurn($message, $debounceSeconds);
        $message->update(['turn_id' => $turnId]);
    }

    /** Staff wrote from the phone: the bot stays quiet for a while and drops what it was about to say. */
    public function staffTookOver(\App\Models\WhatsappConversation $conversation): void
    {
        $minutes = (int) config('agent.handoff.staff_pause_minutes', 60);

        if ($minutes <= 0) {
            return;
        }

        $conversation->state = array_merge($conversation->state ?? [], [
            'staff_active_until' => now()->addMinutes($minutes)->toIso8601String(),
        ]);

        DB::table('whatsapp_message_jobs')
            ->where('whatsapp_conversation_id', $conversation->id)
            ->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'skipped', 'error' => 'STAFF_TOOK_OVER', 'locked_at' => null, 'updated_at' => now()]);
    }

    public static function staffActive(\App\Models\WhatsappConversation $conversation): bool
    {
        $until = $conversation->state['staff_active_until'] ?? null;

        return $until !== null && \Illuminate\Support\Carbon::parse($until)->isFuture();
    }

    /**
     * A pending turn nobody has claimed yet (worker not running, or just
     * slow) shouldn't keep absorbing new messages forever - once its own
     * max_wait has already passed, a new message starts a fresh turn
     * instead of stretching this one further.
     */
    private function maxWaitElapsed(object $turn, int $maxWaitSeconds): bool
    {
        if (! $turn->first_message_at) {
            return false;
        }

        return \Illuminate\Support\Carbon::parse($turn->first_message_at)
            ->addSeconds($maxWaitSeconds)
            ->isPast();
    }

    private function attachToTurn(WhatsappMessage $message, object $turn, int $debounceSeconds, int $maxWaitSeconds): void
    {
        $firstMessageAt = $turn->first_message_at ? \Illuminate\Support\Carbon::parse($turn->first_message_at) : now();
        $processAfter = min(now()->addSeconds($debounceSeconds), $firstMessageAt->copy()->addSeconds($maxWaitSeconds));

        DB::table('whatsapp_message_jobs')
            ->where('id', $turn->id)
            ->update(['process_after' => $processAfter, 'updated_at' => now()]);

        $message->update(['turn_id' => $turn->id]);
    }

    private function createTurn(WhatsappMessage $message, int $debounceSeconds): int
    {
        $conversation = $message->conversation;
        $customer = $conversation->customer;
        $jid = $customer?->jid ?? $conversation->phone;

        return DB::table('whatsapp_message_jobs')->insertGetId([
            'whatsapp_bot_id' => $message->whatsapp_bot_id,
            'whatsapp_conversation_id' => $conversation->id,
            'from' => $jid,
            'reply_jid' => $jid,
            'message' => $message->text ?: '[media]',
            'status' => 'pending',
            'attempts' => 0,
            'first_message_at' => now(),
            'process_after' => now()->addSeconds($debounceSeconds),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
