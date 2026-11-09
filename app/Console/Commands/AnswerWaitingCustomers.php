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
        {--all : also messages without a question mark (thanks, "تمام")}
        {--apply : queue the turns; without it only lists them}';

    protected $description = 'Queue a bot turn for customers whose last message got no reply';

    public function handle(): int
    {
        $waiting = DB::table('whatsapp_conversations as c')
            ->join('whatsapp_bots as b', 'b.id', '=', 'c.whatsapp_bot_id')
            ->join('whatsapp_messages as m', 'm.id', '=', DB::raw('(select max(id) from whatsapp_messages where whatsapp_conversation_id = c.id)'))
            ->where('b.is_active', true)
            ->where('c.status', '!=', 'awaiting_agent')
            ->where('m.direction', 'incoming')->where('m.sender_type', 'customer')
            ->whereBetween('m.created_at', [now()->subHours((int) $this->option('hours')), now()->subMinutes((int) $this->option('minutes'))])
            ->whereNotExists(fn ($q) => $q->from('whatsapp_message_jobs as j')->whereColumn('j.whatsapp_conversation_id', 'c.id')
                ->whereIn('j.status', ['pending', 'processing', 'generated']))
            // one retry per message: a turn made after his message already tried (no loop on a failing number)
            ->whereNotExists(fn ($q) => $q->from('whatsapp_message_jobs as r')->whereColumn('r.whatsapp_conversation_id', 'c.id')
                ->whereRaw('r.created_at > m.created_at + interval 1 minute'))
            ->orderBy('m.created_at')
            ->get(['c.id', 'm.text', 'm.created_at']);

        $queued = 0;

        foreach ($waiting as $row) {
            $conversation = WhatsappConversation::with('customer')->find($row->id);
            $lastOutgoing = WhatsappMessage::where('whatsapp_conversation_id', $row->id)->where('direction', 'outgoing')->max('id') ?? 0;
            $unanswered = WhatsappMessage::where('whatsapp_conversation_id', $row->id)->where('direction', 'incoming')
                ->where('sender_type', 'customer')->where('id', '>', $lastOutgoing)->orderBy('id')->get();
            $asks = $unanswered->contains(fn ($m) => preg_match('/[؟?]/u', (string) $m->text.' '.(string) $m->transcript)
                || preg_match('/^[\s.!،,؟?…]+$/u', (string) $m->text));

            if (! $asks && ! $this->option('all')) {
                continue;
            }

            $this->line($row->id.'  '.$row->created_at.'  «'.mb_substr(str_replace("\n", ' ', (string) $row->text), 0, 50).'»');

            if (! $this->option('apply') || ! $conversation) {
                continue;
            }

            $jid = $conversation->customer?->jid ?? $conversation->phone;
            $jobId = DB::table('whatsapp_message_jobs')->insertGetId([
                'whatsapp_bot_id' => $conversation->whatsapp_bot_id,
                'whatsapp_conversation_id' => $conversation->id,
                'from' => $jid,
                'reply_jid' => $jid,
                'message' => (string) ($unanswered->last()?->text ?: '[media]'),
                'status' => 'pending',
                'attempts' => 0,
                'first_message_at' => now(),
                'process_after' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            WhatsappMessage::whereIn('id', $unanswered->pluck('id'))->update(['turn_id' => $jobId]);
            $queued++;
        }

        $this->info($this->option('apply') ? "Queued {$queued} turns." : 'Dry run - add --apply to queue them.');

        return self::SUCCESS;
    }
}
