<?php

namespace App\Domain\Bots;

use App\Models\WhatsappBot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-11-18: "why is the bot late / why is it stopped" - one bot's live state for the
 * delays tab of its activity page. It mirrors the rules whatsapp:process-jobs uses to pick a turn
 * (ProcessWhatsappMessageJobs::claimNextJob), so every reason it shows is one that really holds
 * the bot back, plus the waiting queue (new customers / old re-answers), today's waiting times and
 * the problems of the last 24 hours in plain words.
 */
class BotDelays
{
    private const STALE_MINUTES = 10;

    /** The time a turn's customer started waiting: his first message in it, else the turn itself. */
    // When the customer started waiting: his first message of the turn sent after the bot last wrote
    // to him. Owner 2026-11-18: a message already answered by another reply (17/11 09:04, answered
    // 11:05) stayed on a later turn and showed a 32-hour wait for a reply that took 38 seconds.
    private const FIRST = "coalesce((select min(m0.created_at) from whatsapp_messages m0 where m0.turn_id = j.id and m0.created_at > coalesce((select max(o.created_at) from whatsapp_messages o where o.whatsapp_conversation_id = j.whatsapp_conversation_id and o.direction = 'outgoing' and (o.turn_id is null or o.turn_id <> j.id) and o.created_at < coalesce(j.processed_at, '9999-12-31')), '1970-01-01')), (select min(m0.created_at) from whatsapp_messages m0 where m0.turn_id = j.id), j.created_at)";

    /** @return list<array{level: string, title: string, detail: string}> what holds this bot back now, worst first */
    public function blockers(WhatsappBot $bot): array
    {
        $out = [];
        $add = function (string $level, string $title, string $detail = '') use (&$out): void {
            $out[] = compact('level', 'title', 'detail');
        };

        if (! $bot->is_active) {
            $add('stop', 'البوت متوقف من الإعدادات', 'مش هيرد على حد لحد ما يتفعّل من إعدادات البوت.');
        }

        if ($bot->session_status !== 'connected') {
            $add('stop', 'الرقم مش متوصل بواتساب', match ($bot->session_status) {
                'logged_out' => 'الرقم خرج من واتساب (اتفصل أو اتحظر) - محتاج مسح QR تاني.',
                'qr' => 'مستني مسح كود QR.',
                'restricted' => 'واتساب مقيّد الرقم دلوقتي.',
                'not_running', null => 'برنامج الرقم مش شغال على السيرفر (مافيش ربط) - محتاج مسح QR.',
                default => 'حالته: '.$bot->session_status.'. الطلبات بتستنى لحد ما يرجع.',
            });
        }

        $limit = $bot->activeLimit();
        if (in_array($limit, ['capped', 'timelock'], true)) {
            $add('stop', 'واتساب مقيّد الرقم', 'مش هيبعت لحد '.($bot->wa_limit_until?->timezone('Africa/Cairo')->format('h:i a d/m') ?? 'ما القيد يتشال').'.');
        } elseif ($limit === 'second_warning') {
            $add('warn', 'إنذار تاني من واتساب', 'بيرد على العملاء اللي بيكتبوا دلوقتي بس، وبربع الحدود.');
        } elseif ($limit === 'first_warning') {
            $add('warn', 'إنذار من واتساب', 'شغال بنص الحدود لحد ما الإنذار يخلص.');
        }

        // The hourly / daily customer caps (config agent.delivery), with a warning or warm-up factor.
        $factor = WhatsappBot::limitFactor($limit);
        $warm = config('agent.delivery.warmup_bots')[$bot->id] ?? null;
        if ($warm && now()->lt(Carbon::parse($warm['until'])->endOfDay())) {
            $factor = min($factor, $warm['factor']);
            $share = match (true) { $warm['factor'] <= 0.25 => 'بربع', $warm['factor'] <= 0.5 => 'بنص', $warm['factor'] <= 0.75 => 'بتلات تربع', default => 'بـ'.round($warm['factor'] * 100).'٪ من' };
            $add('info', 'فترة تسخين بعد الحظر', "شغال {$share} الحدود لحد ".Carbon::parse($warm['until'])->format('d/m').'.');
        }
        foreach ([['hour', 'max_customers_per_hour', 'في الساعة', now()->subHour()], ['day', 'max_customers_per_day', 'في اليوم', now()->subDay()]] as [, $key, $per, $since]) {
            $cap = (int) config('agent.delivery.'.$key, 0);
            if ($cap <= 0) {
                continue;
            }
            $allowed = max(1, (int) floor($cap * $factor));
            $sent = $this->customersSince($bot, $since);
            if ($sent >= $allowed) {
                $add('stop', 'وصل لحد العملاء '.$per, "رد على {$sent} عميل والحد {$allowed}. بيكمل لما الوقت يعدّي.");
            } elseif ($sent >= $allowed * 0.85) {
                $add('warn', 'قرب من حد العملاء '.$per, "رد على {$sent} من {$allowed}.");
            }
        }

        // The turn it is on now (one at a time per number).
        $current = DB::table('whatsapp_message_jobs as j')
            ->leftJoin('whatsapp_conversations as c', 'c.id', '=', 'j.whatsapp_conversation_id')
            ->where('j.whatsapp_bot_id', $bot->id)->whereIn('j.status', ['processing', 'generated'])
            ->whereNotNull('j.locked_at')->where('j.locked_at', '>=', now()->subMinutes(self::STALE_MINUTES))
            ->orderBy('j.locked_at')->first(['j.id', 'j.status', 'j.locked_at', DB::raw('coalesce(c.real_phone, c.phone) as phone')]);
        if ($current) {
            $mins = (int) Carbon::parse($current->locked_at)->diffInMinutes(now());
            $what = $current->status === 'generated' ? 'بيبعت الرد' : 'بيجهّز الرد';
            $current->phone = self::phone($current->phone);
            $mins >= 4
                ? $add('warn', 'رد متعلّق من '.$mins.' دقيقة', "{$what} لـ {$current->phone} ومخلصش. لو فضل كده الرقم مش هياخد عميل تاني لحد ما يعدّي ".self::STALE_MINUTES.' دقايق.')
                : $add('ok', $what.' دلوقتي', "للعميل {$current->phone} من ".max(1, $mins).' دقيقة.');
        }

        // Waiting turns that are not ready yet, and why.
        $later = DB::table('whatsapp_message_jobs')->where('whatsapp_bot_id', $bot->id)->where('status', 'pending')
            ->where('process_after', '>', now())->get(['id', 'attempts', 'whatsapp_conversation_id', 'process_after']);
        if ($later->isNotEmpty()) {
            $staff = DB::table('whatsapp_conversations')->whereIn('id', $later->pluck('whatsapp_conversation_id')->filter())
                ->where('state', 'like', '%staff_active_until%')->pluck('state')
                ->filter(fn ($st) => ($u = json_decode((string) $st, true)['staff_active_until'] ?? null) && Carbon::parse($u)->isFuture())->count();
            $retry = $later->where('attempts', '>=', (int) config('agent.delivery.max_attempts', 3))->count();
            if ($staff) {
                $add('info', "{$staff} عميل مستني الموظف يخلص", 'موظف كتب للعميل من الموبايل، والبوت بيسكت شوية بعده.');
            }
            if ($retry) {
                $add('warn', "{$retry} رد بيتعاد بعد مشكلة", 'الذكاء الاصطناعي أو الإرسال وقع، وبيجرّب تاني كل شوية.');
            }
        }

        $voice = DB::table('whatsapp_messages as m')->join('whatsapp_message_jobs as j', 'j.id', '=', 'm.turn_id')
            ->where('j.whatsapp_bot_id', $bot->id)->whereIn('j.status', ['pending', 'processing'])
            ->where('m.transcription_status', 'pending')->count();
        if ($voice) {
            $add('info', "{$voice} ريكورد بيتحوّل لكلام", 'الرد بيستنى التحويل لحد '.config('agent.turns.transcript_wait_seconds').' ثانية.');
        }

        if (Cache::has('answer_waiting_gap_'.$bot->id)) {
            $add('info', 'فاصل بين ردود العملاء القدام', 'بعد كل رد قديم بيستنى دقيقة قبل القديم اللي بعده (الجديد مش بيستنى).');
        }

        // Workers alive? Ready turns exist on some free number, but none was taken for a while.
        $ready = $this->readyCount($bot);
        $lastPick = DB::table('whatsapp_message_jobs')->max('locked_at');
        if ($ready > 0 && $lastPick && Carbon::parse($lastPick)->lt(now()->subMinutes(5)) && ! $current) {
            $add('stop', 'شغّيلين الردود مش بياخدوا طلبات', 'فيه طلبات جاهزة ومحدش أخد طلب من '.Carbon::parse($lastPick)->diffForHumans(now(), true).'. لازم إعادة تشغيل الشغّيلين.');
        }

        if ($out === [] || collect($out)->every(fn ($b) => $b['level'] === 'info')) {
            $add('ok', $ready > 0 ? 'شغال وبيرد بالترتيب' : 'شغال ومافيش حد مستني', '');
        }

        $rank = ['stop' => 0, 'warn' => 1, 'ok' => 2, 'info' => 3];
        usort($out, fn ($a, $b) => $rank[$a['level']] <=> $rank[$b['level']]);

        return $out;
    }

    /** New customers and old re-answers waiting now, the longest wait, and when it should be done. */
    public function queue(WhatsappBot $bot): array
    {
        $rows = DB::table('whatsapp_message_jobs as j')
            ->where('j.whatsapp_bot_id', $bot->id)->whereIn('j.status', ['pending', 'generated'])
            ->selectRaw("coalesce(j.payload, '') like '%\"answer_waiting%' as old, count(*) n, min(".self::FIRST.') since')
            ->groupBy('old')->get()->keyBy(fn ($r) => (int) $r->old);

        $new = (int) ($rows[0]->n ?? 0);
        $old = (int) ($rows[1]->n ?? 0);

        // How fast it answers now: the average gap between its last answered turns (last 2 hours).
        $done = DB::table('whatsapp_message_jobs')->where('whatsapp_bot_id', $bot->id)->where('status', 'done')
            ->where('processed_at', '>=', now()->subHours(2))->orderBy('processed_at')->pluck('processed_at')
            ->map(fn ($t) => Carbon::parse($t)->timestamp)->values();
        $perTurn = $done->count() >= 3 ? (int) round(($done->last() - $done->first()) / ($done->count() - 1)) : null;

        return [
            'new' => $new,
            'old' => $old,
            'new_since' => isset($rows[0]) ? Carbon::parse($rows[0]->since) : null,
            'old_since' => isset($rows[1]) ? Carbon::parse($rows[1]->since) : null,
            'per_turn_seconds' => $perTurn,
            'per_hour' => $perTurn ? (int) floor(3600 / max(1, $perTurn)) : null,
            'new_done_in' => $perTurn && $new ? $new * $perTurn : null,
            'all_done_in' => $perTurn && ($new + $old) ? ($new + $old) * $perTurn : null,
        ];
    }

    /** Today's answered turns: new customers vs old re-answers, how long they waited. */
    public function today(WhatsappBot $bot): array
    {
        $rows = DB::table('whatsapp_message_jobs as j')
            ->where('j.whatsapp_bot_id', $bot->id)->where('j.status', 'done')->where('j.processed_at', '>=', today())
            ->selectRaw("coalesce(j.payload, '') like '%\"answer_waiting%' as old, timestampdiff(second, ".self::FIRST.', j.processed_at) waited')
            ->get();

        $stats = function (Collection $w): array {
            $w = $w->map(fn ($v) => max(0, (int) $v))->sort()->values();
            $n = $w->count();

            return ['count' => $n, 'median' => $n ? $w[intdiv($n, 2)] : null, 'p90' => $n ? $w[min($n - 1, (int) floor($n * 0.9))] : null, 'max' => $n ? $w->last() : null];
        };

        return [
            'new' => $stats($rows->where('old', 0)->pluck('waited')),
            'old' => $stats($rows->where('old', 1)->pluck('waited')),
            'failed' => DB::table('whatsapp_message_jobs')->where('whatsapp_bot_id', $bot->id)->where('status', 'failed')->where('updated_at', '>=', today())->count(),
        ];
    }

    /** Last 24 hours: what made sends fail, in plain words, most frequent first. */
    public function problems(WhatsappBot $bot): Collection
    {
        return DB::table('whatsapp_message_jobs')->where('whatsapp_bot_id', $bot->id)
            ->where('updated_at', '>=', now()->subDay())
            ->where(fn ($q) => $q->where('status', 'failed')->orWhere(fn ($q) => $q->whereIn('status', ['generated', 'pending'])->whereNotNull('error')))
            ->get(['error', 'updated_at'])
            ->groupBy(fn ($r) => self::explain($r->error)[0])
            ->map(fn ($g, $title) => ['title' => $title, 'hint' => self::explain($g->first()->error)[1], 'count' => $g->count(), 'last' => Carbon::parse($g->max('updated_at'))])
            ->sortByDesc('count')->values();
    }

    /** The bot's last turns: who, new or old, how long he waited, how it ended. */
    public function recent(WhatsappBot $bot, int $limit = 25): Collection
    {
        return DB::table('whatsapp_message_jobs as j')
            ->leftJoin('whatsapp_conversations as c', 'c.id', '=', 'j.whatsapp_conversation_id')
            ->where('j.whatsapp_bot_id', $bot->id)->whereIn('j.status', ['done', 'failed', 'processing', 'generated', 'skipped'])
            ->where('j.updated_at', '>=', now()->subDay())
            ->orderByDesc('j.updated_at')->limit($limit)
            ->get(['j.id', 'j.status', 'j.error', 'j.payload', 'j.updated_at', 'j.processed_at', DB::raw('coalesce(c.real_phone, c.phone) as phone'), 'c.id as conversation_id', DB::raw(self::FIRST.' as since')])
            ->map(fn ($r) => [
                'id' => $r->id,
                'phone' => self::phone($r->phone),
                'conversation_id' => $r->conversation_id,
                'old' => str_contains((string) $r->payload, 'answer_waiting'),
                'since' => Carbon::parse($r->since),
                'at' => Carbon::parse($r->processed_at ?? $r->updated_at),
                'waited' => (int) max(0, Carbon::parse($r->since)->diffInSeconds(Carbon::parse($r->processed_at ?? $r->updated_at))),
                'status' => $r->status,
                'why' => $r->error ? self::explain($r->error)[0] : null,
            ]);
    }

    /** [title, hint] for a stored job error. */
    public static function explain(?string $error): array
    {
        $e = (string) $error;

        return match (true) {
            $e === '' => ['مشكلة من غير تفاصيل', ''],
            // Owner 2026-11-18: the AI provider's errors showed as "مشكلة تانية".
            str_contains($e, 'has no available key') => ['الذكاء الاصطناعي موقوف مؤقتاً', 'سيرفر الذكاء الاصطناعي وقع أو رفض الطلبات، فالسيستم وقّف المفتاح نص دقيقة - بيجرّب تاني لوحده.'],
            str_contains($e, 'connection failed'), str_contains($e, 'call budget exhausted') => ['سيرفر الذكاء الاصطناعي ماردش', 'المزوّد اتأخر أو ماردش خالص - بيجرّب تاني لوحده.'],
            str_contains($e, 'session not found') => ['الرقم ماكانش متوصل وقت الإرسال', 'الرقم اتفصل أو برنامجه اتعمله إعادة تشغيل والرد كان جاهز.'],
            str_contains($e, 'cURL error 52'), str_contains($e, 'Empty reply') => ['برنامج البوت اتعمله إعادة تشغيل وقت الإرسال', 'بيحصل مع التنزيل أو لو البرنامج وقع - الرد بيتعاد.'],
            str_contains($e, 'cURL error 28'), str_contains($e, 'timed out') => ['البوت ماردش في الوقت', 'الإرسال استنى أكتر من اللازم (طابور طويل على الرقم أو الرقم بيعيد الاتصال).'],
            str_contains($e, 'cURL error 7'), str_contains($e, 'Connection refused') => ['برنامج البوت مش شغال', 'السيستم مش قادر يوصل لبرنامج الواتساب على السيرفر.'],
            str_contains($e, 'send cancelled') => ['الإرسال اتلغى', 'السيستم مااستناش لحد ما الرقم يبعت.'],
            str_contains($e, 'Delivery failed: 200') => ['واتساب ماقبلش الرسالة', 'الرقم كان بيعيد الاتصال أو واتساب رفض الإرسال للعميل ده.'],
            str_contains($e, 'Delivery failed') => ['الإرسال فشل', mb_substr($e, 0, 120)],
            $e === 'ABANDONED_WHILE_PROCESSING' => ['الرد اتعلّق والشغّيل وقف', 'شغّيل الردود اتعمله إعادة تشغيل وهو بيرد.'],
            $e === 'STAFF_TOOK_OVER' => ['موظف رد بنفسه', 'البوت ساب الرد للموظف - مش مشكلة.'],
            str_starts_with($e, 'HELD_AFTER_BAN') => ['اتوقف بعد الحظر', 'ردود قديمة اتوقفت عشان ماتحظرش الرقم تاني.'],
            (bool) preg_match('/quota|rate.?limit|429|503|overloaded|RESOURCE_EXHAUSTED|UNAVAILABLE/i', $e) => ['الذكاء الاصطناعي مش متاح', 'المزوّد واقع أو الحصة خلصت - بيجرّب تاني لوحده.'],
            default => ['مشكلة تانية', mb_substr($e, 0, 120)],
        };
    }

    /** "201114014299:0" / "201114014299@s.whatsapp.net" -> "201114014299" */
    private static function phone(?string $p): ?string
    {
        return $p === null ? null : preg_replace('/[:@].*$/', '', $p);
    }

    private function customersSince(WhatsappBot $bot, Carbon $since): int
    {
        return (int) DB::table('whatsapp_messages')->where('whatsapp_bot_id', $bot->id)->where('direction', 'outgoing')
            ->where('delivery_status', 'sent')->where('created_at', '>=', $since)->distinct()->count('whatsapp_conversation_id');
    }

    private function readyCount(WhatsappBot $bot): int
    {
        return DB::table('whatsapp_message_jobs')->where('whatsapp_bot_id', $bot->id)->whereIn('status', ['pending', 'generated'])
            ->where(fn ($q) => $q->whereNull('process_after')->orWhere('process_after', '<=', now()))->count();
    }
}
