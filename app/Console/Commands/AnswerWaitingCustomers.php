<?php

namespace App\Console\Commands;

use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-11-09: customers who wrote "؟" or asked something were left without any reply - the
 * bot chose silence, or its reply never reached them. This finds conversations whose last message
 * is an unanswered customer question and gives them a new bot turn. Dry run unless --apply.
 */
class AnswerWaitingCustomers extends Command
{
    protected $signature = 'whatsapp:answer-waiting
        {--minutes=5 : only messages at least this old}
        {--hours=48 : only messages at most this old}
        {--all : kept for compatibility; every unanswered message is looked at}
        {--bot= : only this bot}
        {--gap-min=15 : seconds at least between two re-answers (anti-ban pacing)}
        {--gap-max=30 : seconds at most between two re-answers}
        {--apply : queue the turns; without it only lists them}';

    protected $description = 'Queue a bot turn for customers whose last message got no reply';

    public function handle(): int
    {
        $waiting = DB::table('whatsapp_conversations as c')
            ->join('whatsapp_bots as b', 'b.id', '=', 'c.whatsapp_bot_id')
            // a bot reply that never reached him (delivery failed: number logged out, worker down) is no reply
            ->join('whatsapp_messages as m', 'm.id', '=', DB::raw("(select max(id) from whatsapp_messages where whatsapp_conversation_id = c.id and not (direction = 'outgoing' and delivery_status = 'failed'))"))
            ->where('b.is_active', true)
            ->whereIn('b.session_status', ['connected'])
            ->when($this->option('bot'), fn ($q, $bot) => $q->where('c.whatsapp_bot_id', (int) $bot))
            ->where('c.status', '!=', 'awaiting_agent')
            ->where('m.direction', 'incoming')->where('m.sender_type', 'customer')
            // Owner 2026-11-10: the whole --hours window (10 days on the schedule) is answered, recovered or not.
            ->whereBetween('m.created_at', [now()->subHours((int) $this->option('hours')), now()->subMinutes((int) $this->option('minutes'))])
            ->whereNotExists(fn ($q) => $q->from('whatsapp_message_jobs as j')->whereColumn('j.whatsapp_conversation_id', 'c.id')
                ->whereIn('j.status', ['pending', 'processing', 'generated']))
            // one retry per message: a turn made after his message already tried (no loop on a failing number)
            // Only a turn that answered customer messages counts: reminders and dashboard sends are jobs
            // too, and the reminders retried while a number was logged out hid every waiting customer.
            ->whereNotExists(fn ($q) => $q->from('whatsapp_message_jobs as r')->whereColumn('r.whatsapp_conversation_id', 'c.id')
                ->whereRaw('r.created_at > m.created_at + interval 1 minute')
                ->whereExists(fn ($i) => $i->from('whatsapp_messages as im')->whereColumn('im.turn_id', 'r.id')->where('im.direction', 'incoming')))
            // Live 2026-11-10: after bot 93's relink 1,303 chats were queued oldest first, so a
            // customer who wrote at 01:30 that night was slotted for 22:41 behind ten-day-old ones.
            ->orderByDesc('m.created_at')
            ->get(['c.id', 'm.text', 'm.created_at']);

        $queued = 0;

        // Owner 2026-11-10: thousands of re-answers at once would get the number banned and push live
        // customers behind them. Each re-answer gets its own slot after the previous one (per bot).
        // A customer who writes again meanwhile is answered at once: TurnScheduler::attachToTurn
        // pulls the turn to now+debounce and makes it a live turn.
        // Owner 2026-11-10: every number works around the clock - no night hold. Re-answers are
        // paced 15-30 s apart per bot, newest first; a customer writing live is claimed before
        // them (ProcessWhatsappMessageJobs::claimNextJob), then the backlog carries on.
        $slots = [];
        $nextSlot = function (int $botId) use (&$slots) {
            $slots[$botId] ??= max(now(), \Illuminate\Support\Carbon::parse(DB::table('whatsapp_message_jobs')
                ->where('whatsapp_bot_id', $botId)->where('status', 'pending')->where('payload', 'like', '%"answer_waiting%')
                ->max('process_after') ?? now()));

            return $slots[$botId] = $slots[$botId]->copy()->addSeconds(random_int((int) $this->option('gap-min'), max((int) $this->option('gap-min'), (int) $this->option('gap-max'))));
        };

        foreach ($waiting as $row) {
            $conversation = WhatsappConversation::with('customer')->find($row->id);
            $lastOutgoing = WhatsappMessage::where('whatsapp_conversation_id', $row->id)->where('direction', 'outgoing')
                ->where(fn ($q) => $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'failed'))->max('id') ?? 0;
            $unanswered = WhatsappMessage::where('whatsapp_conversation_id', $row->id)->where('direction', 'incoming')
                ->where('sender_type', 'customer')->where('id', '>', $lastOutgoing)->orderBy('id')->get();

            // Owner 2026-11-09: no keyword test decides whether he expects an answer ("؟" missed
            // "عاوز tvs" and "Tvs 150 f"). The agent reads his words and stays silent itself after
            // a plain "شكرا" - one extra look per message, never a loop (see the retry rule above).
            if ($unanswered->isEmpty()) {
                continue;
            }

            // A turn that ran and chose silence ("شكرا" after a closing reply) was the agent's answer;
            // answering it hours later is itself a strange message. Only a message no turn handled
            // (written while the number was down, a failed or skipped turn) or whose reply never
            // reached him is answered again.
            $turnIds = $unanswered->pluck('turn_id')->filter()->unique();
            $handled = $turnIds->count() === 1 && $unanswered->every(fn ($m) => $m->turn_id !== null)
                && DB::table('whatsapp_message_jobs')->where('id', $turnIds->first())->where('status', 'done')->exists()
                && ! WhatsappMessage::where('turn_id', $turnIds->first())->where('direction', 'outgoing')->where('delivery_status', 'failed')->exists()
                // the old "." / "؟" shortcut answered silence before the model ever read him
                && ! DB::table('ai_traces')->where('turn_id', $turnIds->first())->where('guard_events', 'like', '%PUNCTUATION_OR_ACK_ONLY%')->exists();

            if ($handled) {
                continue;
            }

            $this->line($row->id.'  '.$row->created_at.'  «'.mb_substr(str_replace("\n", ' ', (string) $row->text), 0, 50).'»');

            if (! $this->option('apply') || ! $conversation) {
                continue;
            }

            $jid = $conversation->customer?->jid ?? $conversation->phone;
            $slot = $nextSlot((int) $conversation->whatsapp_bot_id);
            $jobId = DB::table('whatsapp_message_jobs')->insertGetId([
                'whatsapp_bot_id' => $conversation->whatsapp_bot_id,
                'whatsapp_conversation_id' => $conversation->id,
                'from' => $jid,
                'reply_jid' => $jid,
                'message' => (string) ($unanswered->last()?->text ?: '[media]'),
                'payload' => json_encode(['source' => 'answer_waiting']),
                'status' => 'pending',
                'attempts' => 0,
                'first_message_at' => $slot,
                'process_after' => $slot,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            WhatsappMessage::whereIn('id', $unanswered->pluck('id'))->update(['turn_id' => $jobId]);
            $queued++;
        }

        $this->info($this->option('apply')
            ? "Queued {$queued} turns".($slots !== [] ? ', last at '.collect($slots)->max()->timezone('Africa/Cairo')->format('m-d H:i').' Cairo' : '').'.'
            : 'Dry run - add --apply to queue them.');

        return self::SUCCESS;
    }
}
