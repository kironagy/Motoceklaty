<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-11-10: "يبقى واضحلي ايه اللي بيرد" - every late re-answer (whatsapp:answer-waiting):
 * the customer's waiting message, when he sent it, and what the bot sent (or that it stayed
 * silent, or when it is scheduled). --csv writes the same rows to a file.
 */
class AnswerWaitingReport extends Command
{
    protected $signature = 'whatsapp:answer-waiting-report
        {--hours=24 : re-answers queued in the last N hours}
        {--bot= : only this bot}
        {--csv= : also write the rows to this CSV path}';

    protected $description = 'List late re-answers: his waiting message and the bot reply';

    public function handle(): int
    {
        $jobs = DB::table('whatsapp_message_jobs as j')
            ->join('whatsapp_conversations as c', 'c.id', '=', 'j.whatsapp_conversation_id')
            ->leftJoin('customers as cu', 'cu.id', '=', 'c.customer_id')
            ->where('j.payload', 'like', '%"answer_waiting"%')
            ->where('j.created_at', '>=', now()->subHours((int) $this->option('hours')))
            ->when($this->option('bot'), fn ($q, $bot) => $q->where('j.whatsapp_bot_id', (int) $bot))
            ->orderBy('j.process_after')
            ->get(['j.id', 'j.whatsapp_bot_id as bot', 'j.whatsapp_conversation_id as conversation', 'j.status', 'j.process_after',
                'cu.push_name', 'c.phone']);

        $rows = [];
        foreach ($jobs as $job) {
            $in = DB::table('whatsapp_messages')->where('turn_id', $job->id)->where('direction', 'incoming')->orderBy('id')
                ->get(['created_at', 'text', 'transcript', 'type']);
            $out = DB::table('whatsapp_messages')->where('turn_id', $job->id)->where('direction', 'outgoing')->orderBy('id')
                ->get(['text', 'type', 'delivery_status']);

            $reply = match (true) {
                $job->status === 'pending' => 'مستني دوره: '.\Illuminate\Support\Carbon::parse($job->process_after)->format('m-d H:i'),
                $out->isEmpty() => '— سكت (الرسالة مش محتاجة رد)',
                default => $out->map(fn ($m) => ($m->text ?: '['.$m->type.']').($m->delivery_status === 'failed' ? ' (فشل الإرسال)' : ''))->implode(' | '),
            };

            $rows[] = [
                'conv' => $job->conversation,
                'bot' => $job->bot,
                'customer' => trim(($job->push_name ?: '').' '.$job->phone),
                'his_message_at' => $in->first()?->created_at ? substr($in->first()->created_at, 5, 11) : '',
                'his_message' => $in->map(fn ($m) => $m->text ?: $m->transcript ?: '['.$m->type.']')->implode(' / '),
                'bot_reply' => $reply,
            ];
        }

        $short = fn ($v, $n) => mb_strlen((string) $v) > $n ? mb_substr((string) $v, 0, $n).'…' : (string) $v;
        $this->table(['conv', 'bot', 'his msg at', 'his message', 'bot reply'], array_map(fn ($r) => [
            $r['conv'], $r['bot'], $r['his_message_at'], $short(str_replace("\n", ' ', $r['his_message']), 45), $short(str_replace("\n", ' ', $r['bot_reply']), 90),
        ], $rows));

        $replied = collect($rows)->filter(fn ($r) => ! str_starts_with($r['bot_reply'], '—') && ! str_starts_with($r['bot_reply'], 'مستني'))->count();
        $pending = collect($rows)->filter(fn ($r) => str_starts_with($r['bot_reply'], 'مستني'))->count();
        $this->info(count($rows)." re-answers: {$replied} replied, ".(count($rows) - $replied - $pending)." silent, {$pending} pending.");

        if ($path = $this->option('csv')) {
            $f = fopen($path, 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, array_keys($rows[0] ?? ['conv' => 1, 'bot' => 1, 'customer' => 1, 'his_message_at' => 1, 'his_message' => 1, 'bot_reply' => 1]));
            foreach ($rows as $r) {
                fputcsv($f, $r);
            }
            fclose($f);
            $this->info("CSV: {$path}");
        }

        return self::SUCCESS;
    }
}
