<?php

namespace App\Domain\Monitoring;

use App\Models\AiTrace;
use App\Models\WhatsappMessage;
use Illuminate\Support\Carbon;

/**
 * How the bot's replies went over a window (owner 2026-10-04: "مش عاوز نسبة
 * المشاكل تبقى حتى 0.01%"): every refusal, every reply sent back by the
 * reviewer, every fallback - read from the turn traces, so the owner sees
 * the trend instead of finding mistakes one screenshot at a time.
 */
class ReplyQuality
{
    /** Refusals that are bookkeeping, not mistakes. */
    private const NOT_A_PROBLEM = ['RESCUED_WITHOUT_UNVERIFIED_SENTENCES'];

    /** OBS-004: a logged silent rewrite (REWRITE:tidy...) is bookkeeping too. */
    public static function notAProblem(string $code): bool
    {
        return in_array($code, self::NOT_A_PROBLEM, true) || str_starts_with($code, 'REWRITE:');
    }

    public function summary(Carbon $since): array
    {
        $traces = AiTrace::where('created_at', '>=', $since)->get(['id', 'status', 'guard_events', 'created_at', 'updated_at', 'conversation_id']);
        $total = $traces->count();
        $codes = [];
        $withProblem = 0;
        $reviewRedo = 0;

        foreach ($traces as $trace) {
            $events = collect($trace->guard_events ?? [])->pluck('code')->filter()->reject(fn ($c) => self::notAProblem($c));

            if ($events->isNotEmpty()) {
                $withProblem++;
            }

            $reviewRedo += $events->filter(fn ($c) => $c === 'REVIEW_REDO')->count();

            foreach ($events as $code) {
                $codes[$code] = ($codes[$code] ?? 0) + 1;
            }
        }

        arsort($codes);
        $seconds = $traces->map(fn ($t) => $t->updated_at && $t->created_at ? $t->created_at->diffInSeconds($t->updated_at) : null)->filter()->sort()->values();

        return [
            'replies' => $total,
            'clean' => $total - $withProblem,
            'clean_percent' => $total > 0 ? round(100 * ($total - $withProblem) / $total, 1) : null,
            'fallbacks' => $traces->where('status', 'fallback')->count(),
            'errors' => $traces->where('status', 'error')->count(),
            'review_redo' => $reviewRedo,
            'avg_seconds' => $seconds->isEmpty() ? null : (int) round($seconds->avg()),
            'p90_seconds' => $seconds->isEmpty() ? null : (int) $seconds[(int) floor(0.9 * ($seconds->count() - 1))],
            'codes' => array_slice($codes, 0, 15, true),
        ];
    }

    /** The latest turns that needed fixing or fell back, newest first. */
    public function problemTurns(Carbon $since, int $limit = 30): array
    {
        return AiTrace::where('created_at', '>=', $since)
            ->where(fn ($q) => $q->whereIn('status', ['fallback', 'error'])->orWhereRaw("JSON_LENGTH(guard_events) > 0"))
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(function (AiTrace $trace) {
                $codes = collect($trace->guard_events ?? [])->pluck('code')->filter()->reject(fn ($c) => self::notAProblem($c))->values();

                if ($codes->isEmpty() && ! in_array($trace->status, ['fallback', 'error'], true)) {
                    return null;
                }

                $customer = WhatsappMessage::where('whatsapp_conversation_id', $trace->conversation_id)->where('turn_id', $trace->turn_id)
                    ->where('direction', 'incoming')->orderBy('id')->pluck('text')->filter()->implode(' / ');
                $reply = WhatsappMessage::where('whatsapp_conversation_id', $trace->conversation_id)->where('turn_id', $trace->turn_id)
                    ->where('direction', 'outgoing')->orderBy('id')->pluck('text')->filter()->implode(' / ');
                $problems = collect($trace->guard_events ?? [])->pluck('args.problems')->flatten()->filter()->take(2)->implode(' ');

                return [
                    'id' => $trace->id,
                    'at' => $trace->created_at?->format('m-d H:i'),
                    'conversation_id' => $trace->conversation_id,
                    'status' => $trace->status,
                    'codes' => $codes->unique()->implode('، '),
                    'customer' => mb_substr($customer, 0, 160),
                    'reply' => mb_substr($reply, 0, 200),
                    'problems' => mb_substr($problems, 0, 220),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
