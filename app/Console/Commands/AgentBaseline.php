<?php

namespace App\Console\Commands;

use App\Agent\Context\TokenEstimator;
use App\Domain\Monitoring\ReplyQuality;
use App\Models\AiCall;
use App\Models\AiTrace;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild PRE-001 / PERF-001: the numbers a change is judged against -
 * model calls, tokens, latency, guard rejections, fallbacks and cost per
 * turn - read from the turns already recorded (ai_traces, ai_usage_logs,
 * ai_calls). Costs nothing: no model is called. Writes raw JSON plus a
 * readable copy under docs/baselines/.
 */
class AgentBaseline extends Command
{
    protected $signature = 'agent:baseline
        {--since= : first day (Y-m-d), default 7 days ago}
        {--until= : last moment (Y-m-d H:i), default now}
        {--name=baseline : file name prefix}
        {--dir= : output folder, default docs/baselines}';

    protected $description = 'Freeze calls/turn, tokens/turn, latency, guard rejections, fallbacks and cost of recorded turns into docs/baselines/.';

    public function handle(): int
    {
        $since = Carbon::parse($this->option('since') ?: now()->subDays(7)->toDateString())->startOfDay();
        $until = $this->option('until') ? Carbon::parse($this->option('until')) : now();

        $metrics = $this->metrics($since, $until);

        $dir = $this->option('dir') ?: base_path('docs/baselines');
        @mkdir($dir, 0755, true);
        $file = $dir.'/'.$this->option('name').'_'.$since->format('Y-m-d').'_'.$until->format('Y-m-d');
        file_put_contents($file.'.json', json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
        file_put_contents($file.'.md', $this->markdown($metrics));

        $this->line(json_encode($metrics['turns'], JSON_UNESCAPED_UNICODE));
        $this->info("Written: {$file}.json / .md");

        return self::SUCCESS;
    }

    public function metrics(Carbon $since, Carbon $until, ?string $runner = null): array
    {
        $query = AiTrace::whereBetween('created_at', [$since, $until]);

        if ($runner !== null) {
            $query->whereIn('id', AiCall::where('runner', $runner)->whereNotNull('trace_id')->select('trace_id'));
        }

        $traces = $query->get(['id', 'status', 'guard_events', 'context_manifest', 'input_tokens', 'output_tokens', 'created_at', 'updated_at']);

        $calls = $traces->map(fn ($t) => (int) data_get($t->context_manifest, 'usage.model_calls', 0))->filter()->values();
        $inputs = $traces->map(fn ($t) => (int) (data_get($t->context_manifest, 'usage.input_tokens') ?: $t->input_tokens))->filter()->values();
        $cached = $traces->sum(fn ($t) => (int) data_get($t->context_manifest, 'usage.cached_tokens', 0));
        $seconds = $traces->map(fn ($t) => $t->created_at && $t->updated_at ? $t->created_at->diffInSeconds($t->updated_at) : null)->filter(fn ($s) => $s !== null)->values();

        $codes = [];
        $rejected = 0;
        $rewrites = 0;
        foreach ($traces as $trace) {
            $events = collect($trace->guard_events ?? [])->pluck('code')->filter();
            $rewrites += $events->filter(fn ($c) => str_starts_with($c, 'REWRITE:'))->count();
            $problems = $events->reject(fn ($c) => ReplyQuality::notAProblem($c));
            $rejected += $problems->isNotEmpty() ? 1 : 0;
            foreach ($problems as $code) {
                $codes[$code] = ($codes[$code] ?? 0) + 1;
            }
        }
        arsort($codes);

        $usage = DB::table('ai_usage_logs')->whereBetween('created_at', [$since, $until])
            ->selectRaw('source, COUNT(*) calls, SUM(input_tokens) input_tokens, SUM(cached_tokens) cached_tokens, SUM(output_tokens) output_tokens, SUM(thoughts_tokens) thoughts_tokens, SUM(cost_usd) cost_usd')
            ->groupBy('source')->get()->keyBy('source')->map(fn ($r) => array_map(fn ($v) => is_numeric($v) ? $v + 0 : $v, (array) $r))->all();

        $replies = max(1, $traces->whereIn('status', ['done', 'fallback'])->count());
        $cost = (float) collect($usage)->sum('cost_usd');

        return [
            'generated_at' => now()->toDateTimeString(),
            'window' => ['since' => $since->toDateTimeString(), 'until' => $until->toDateTimeString(), 'runner' => $runner],
            'environment' => [
                'model' => config('agent.model'),
                'fallback_models' => config('agent.fallback_models'),
                'reasoning_effort' => config('agent.openai_reasoning_effort'),
                'code_version' => trim((string) @shell_exec('git -C '.escapeshellarg(base_path()).' rev-parse --short HEAD 2>/dev/null')),
            ],
            'turns' => [
                'count' => $traces->count(),
                'status' => $traces->countBy('status')->all(),
                'rejected_turns' => $rejected,
                'rejected_percent' => $traces->isEmpty() ? null : round(100 * $rejected / $traces->count(), 1),
                'fallback_percent' => $traces->isEmpty() ? null : round(100 * $traces->where('status', 'fallback')->count() / $traces->count(), 1),
                'silent_rewrites' => $rewrites,
            ],
            'main_loop_calls_per_turn' => $this->distribution($calls) + ['histogram' => $calls->countBy(fn ($c) => $c >= 4 ? '4+' : (string) $c)->sortKeys()->all()],
            'input_tokens_per_turn' => $this->distribution($inputs) + ['cached_share_percent' => $inputs->sum() > 0 ? round(100 * $cached / $inputs->sum(), 1) : null],
            'input_tokens_per_main_call' => $calls->sum() > 0 ? (int) round($inputs->sum() / $calls->sum()) : null,
            'latency_seconds' => $this->distribution($seconds),
            'guard_codes' => $codes,
            'ai_usage_by_source' => $usage,
            'cost' => ['usd_total' => round($cost, 4), 'cents_per_reply' => round(100 * $cost / $replies, 3)],
            'ai_calls' => $this->aiCalls($since, $until, $runner),
        ];
    }

    /** OBS-001 rows, when the window has them: every call incl. side calls, per turn and per label. */
    private function aiCalls(Carbon $since, Carbon $until, ?string $runner): ?array
    {
        $rows = AiCall::whereBetween('created_at', [$since, $until])->when($runner, fn ($q) => $q->where('runner', $runner))
            ->get(['turn_id', 'label', 'outcome', 'provider', 'prompt_chars', 'input_tokens', 'latency_ms']);

        if ($rows->isEmpty()) {
            return null;
        }

        $perTurn = $rows->whereNotNull('turn_id')->groupBy('turn_id')->map->count()->values();
        $calibration = $rows->where('outcome', 'ok')->whereNotNull('prompt_chars')->where('input_tokens', '>', 0)->take(-50)
            ->map(fn ($r) => abs((int) ceil($r->prompt_chars / TokenEstimator::charsPerToken($r->provider)) - $r->input_tokens) / $r->input_tokens);

        return [
            'total' => $rows->count(),
            'failed' => $rows->where('outcome', '!=', 'ok')->count(),
            'by_label' => $rows->countBy('label')->sortDesc()->all(),
            'calls_per_turn' => $this->distribution($perTurn),
            'latency_ms_by_label' => $rows->groupBy('label')->map(fn ($g) => $this->distribution($g->pluck('latency_ms')->values()))->all(),
            // OBS-005 check: estimate vs billed input tokens on the last 50 text calls
            'token_estimate_error_percent' => $calibration->isEmpty() ? null : [
                'calls' => $calibration->count(),
                'mean' => round(100 * $calibration->avg(), 1),
                'max' => round(100 * $calibration->max(), 1),
            ],
        ];
    }

    private function distribution(\Illuminate\Support\Collection $values): array
    {
        $sorted = $values->map(fn ($v) => (float) $v)->sort()->values();
        $at = fn (float $p) => $sorted->isEmpty() ? null : $sorted[(int) floor($p * ($sorted->count() - 1))];

        return [
            'n' => $sorted->count(),
            'avg' => $sorted->isEmpty() ? null : round($sorted->avg(), 2),
            'p10' => $at(0.1), 'p50' => $at(0.5), 'p90' => $at(0.9), 'p95' => $at(0.95),
            'max' => $sorted->max(),
        ];
    }

    private function markdown(array $m): string
    {
        $row = fn (string $label, $value) => '| '.$label.' | '.(is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) ($value ?? '—')).' |';

        return implode("\n", [
            '# Agent baseline '.$m['window']['since'].' → '.$m['window']['until'],
            '',
            'Generated '.$m['generated_at'].' by `php artisan agent:baseline` from recorded traces (no model calls). Raw numbers: the `.json` file next to this one.',
            '',
            '| Metric | Value |',
            '|---|---|',
            $row('Environment', $m['environment']),
            $row('Turns', $m['turns']['count']),
            $row('Status', $m['turns']['status']),
            $row('Rejected-draft turns %', $m['turns']['rejected_percent']),
            $row('Fallback turns %', $m['turns']['fallback_percent']),
            $row('Main-loop calls/turn', $m['main_loop_calls_per_turn']),
            $row('Input tokens/turn', $m['input_tokens_per_turn']),
            $row('Input tokens/main call', $m['input_tokens_per_main_call']),
            $row('Latency seconds', $m['latency_seconds']),
            $row('Cost', $m['cost']),
            $row('All AI calls (ai_calls)', $m['ai_calls'] === null ? 'not recorded in this window (OBS-001 rows start after deploy)' : $m['ai_calls']),
            '',
            '## Guard codes (turn-level occurrences)',
            '',
            '| Code | Count |',
            '|---|---|',
            ...array_map(fn ($c, $n) => "| {$c} | {$n} |", array_keys($m['guard_codes']), $m['guard_codes']),
            '',
            '## AI usage by source (ai_usage_logs)',
            '',
            '| Source | Calls | Input | Cached | Output | Thoughts | USD |',
            '|---|---|---|---|---|---|---|',
            ...array_map(fn ($s, $r) => "| {$s} | {$r['calls']} | {$r['input_tokens']} | {$r['cached_tokens']} | {$r['output_tokens']} | {$r['thoughts_tokens']} | ".round((float) $r['cost_usd'], 4).' |', array_keys($m['ai_usage_by_source']), $m['ai_usage_by_source']),
            '',
        ]);
    }
}
