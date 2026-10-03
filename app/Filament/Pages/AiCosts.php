<?php

namespace App\Filament\Pages;

use App\Models\AiModelPrice;
use App\Models\AiUsageLog;
use App\Models\GeminiApiKey;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-10-02: "عاوز اعرف كل سنت بيتحسب والاجمالي الي عندي". Every
 * Gemini call is logged in ai_usage_logs with its tokens and cost (paid keys
 * only cost money); this page adds them up against the key's credit.
 */
class AiCosts extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'تكلفة الذكاء الاصطناعي';

    protected static ?string $navigationGroup = 'البوت الذكي';

    protected static ?string $title = 'تكلفة الذكاء الاصطناعي';

    protected static ?int $navigationSort = 91;

    protected static string $view = 'filament.pages.ai-costs';

    /** @var array<int, array{credit_usd: mixed, credit_since: mixed}> */
    public array $credits = [];

    /** @var array<int, array{input: mixed, cached: mixed, output: mixed}> */
    public array $prices = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->is_super_admin || $user?->role === 'super_admin');
    }

    public function mount(): void
    {
        foreach (GeminiApiKey::where('is_paid', true)->get() as $key) {
            $this->credits[$key->id] = [
                'credit_usd' => $key->credit_usd,
                'credit_since' => $key->credit_since?->format('Y-m-d H:i'),
            ];
        }

        foreach (AiModelPrice::orderBy('model_code')->get() as $price) {
            $this->prices[$price->id] = ['input' => $price->input_per_million, 'cached' => $price->cached_per_million, 'output' => $price->output_per_million];
        }
    }

    public function saveCredits(): void
    {
        foreach ($this->credits as $id => $row) {
            GeminiApiKey::whereKey($id)->update([
                'credit_usd' => is_numeric($row['credit_usd'] ?? null) ? (float) $row['credit_usd'] : null,
                'credit_since' => filled($row['credit_since'] ?? null) ? Carbon::parse($row['credit_since']) : now(),
            ]);
        }

        Notification::make()->title('اتحفظ الرصيد')->success()->send();
    }

    public function savePrices(): void
    {
        foreach ($this->prices as $id => $row) {
            AiModelPrice::whereKey($id)->update([
                'input_per_million' => (float) ($row['input'] ?? 0),
                'cached_per_million' => (float) ($row['cached'] ?? 0),
                'output_per_million' => (float) ($row['output'] ?? 0),
            ]);
        }

        Notification::make()->title('اتحفظت الأسعار - بتتطبق على الطلبات الجاية')->success()->send();
    }

    public function getPaidKeys(): array
    {
        return self::paidKeySummaries();
    }

    /** Also shown on the AI health page (owner 2026-10-02). */
    public static function paidKeySummaries(): array
    {
        return GeminiApiKey::where('is_paid', true)->orderBy('id')->get()->map(function (GeminiApiKey $key) {
            $since = $key->credit_since ?? $key->created_at;
            $logs = AiUsageLog::where('gemini_api_key_id', $key->id)->where('created_at', '>=', $since);
            $spent = (float) (clone $logs)->sum('cost_usd');
            $calls = (int) (clone $logs)->count();
            $replies = (int) DB::table('ai_traces')->where('created_at', '>=', $since)->where('status', 'done')->count();
            $botCost = (float) (clone $logs)->whereIn('source', ['bot', 'reply'])->sum('cost_usd');
            $remaining = $key->credit_usd !== null ? (float) $key->credit_usd - $spent : null;

            // Average day over the last week (or since the top-up, if newer),
            // counting a started day as at least an hour so day one is not wild.
            $weekStart = max($since?->timestamp ?? 0, now()->subDays(7)->timestamp);
            $weekDays = max(1 / 24, (now()->timestamp - $weekStart) / 86400);
            $perDay = (float) AiUsageLog::where('gemini_api_key_id', $key->id)->where('created_at', '>=', Carbon::createFromTimestamp($weekStart))->sum('cost_usd') / $weekDays;

            // What the cached tokens would have cost at the normal input price.
            $saved = (float) DB::table('ai_usage_logs')->join('ai_model_prices', 'ai_model_prices.model_code', '=', 'ai_usage_logs.model_code')
                ->where('gemini_api_key_id', $key->id)->where('ai_usage_logs.created_at', '>=', $since)
                ->selectRaw('SUM(cached_tokens * (input_per_million - cached_per_million)) / 1000000 as saved')->value('saved');
            $tokens = (clone $logs)->selectRaw('SUM(input_tokens) as input, SUM(cached_tokens) as cached')->first();

            return [
                'id' => $key->id,
                'name' => $key->name,
                'is_active' => (bool) $key->is_active,
                'credit' => $key->credit_usd !== null ? (float) $key->credit_usd : null,
                'since' => $since?->format('Y-m-d H:i'),
                'spent' => $spent,
                'remaining' => $remaining,
                'today' => (float) AiUsageLog::where('gemini_api_key_id', $key->id)->where('created_at', '>=', now()->startOfDay())->sum('cost_usd'),
                'month' => (float) AiUsageLog::where('gemini_api_key_id', $key->id)->where('created_at', '>=', now()->startOfMonth())->sum('cost_usd'),
                'per_day' => $perDay,
                'days_left' => ($remaining !== null && $perDay > 0) ? (int) floor($remaining / $perDay) : null,
                'saved' => $saved,
                'cached_percent' => ($tokens?->input ?? 0) > 0 ? (int) round($tokens->cached / $tokens->input * 100) : null,
                'calls' => $calls,
                'per_reply' => $replies > 0 ? $botCost / $replies : null,
                'replies_left' => ($replies > 0 && $botCost > 0 && $key->credit_usd !== null) ? (int) floor(((float) $key->credit_usd - $spent) / ($botCost / $replies)) : null,
            ];
        })->all();
    }

    public function getDaily(): array
    {
        return AiUsageLog::where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->selectRaw('DATE(created_at) as day, SUM(cost_usd) as cost, COUNT(*) as calls, SUM(is_paid) as paid_calls, SUM(input_tokens) as input_tokens, SUM(cached_tokens) as cached_tokens, SUM(output_tokens + thoughts_tokens) as output_tokens')
            ->groupBy('day')->orderByDesc('day')->get()->toArray();
    }

    public function getByModel(): array
    {
        return AiUsageLog::where('created_at', '>=', now()->subDays(30))
            ->selectRaw('model_code, source, SUM(cost_usd) as cost, COUNT(*) as calls, SUM(is_paid) as paid_calls')
            ->groupBy('model_code', 'source')->orderByDesc('cost')->get()->toArray();
    }

    public function getRecent(): array
    {
        return AiUsageLog::with('apiKey:id,name')->latest('id')->limit(60)->get()->map(fn (AiUsageLog $log) => [
            'at' => $log->created_at?->format('m-d H:i:s'),
            'key' => $log->apiKey?->name ?? '#'.$log->gemini_api_key_id,
            'paid' => $log->is_paid,
            'model' => $log->model_code,
            'source' => $log->source,
            'input' => $log->input_tokens,
            'cached' => $log->cached_tokens,
            'output' => $log->output_tokens + $log->thoughts_tokens,
            'cost' => $log->cost_usd,
        ])->all();
    }

    public function getPriceRows(): array
    {
        return AiModelPrice::orderBy('model_code')->get(['id', 'model_code'])->toArray();
    }
}
