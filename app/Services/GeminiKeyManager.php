<?php

namespace App\Services;

use App\Models\GeminiApiKey;
use App\Models\GeminiApiKeyModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GeminiKeyManager
{
    /**
     * Owner 2026-10-02: the paid key is for customers only. Every AI call
     * says what it is for ("reply", "image", "simulator"...), and
     * config('gemini.key_pools') says which keys that work may spend:
     *   paid       - the paid key; the free keys only once it is finished
     *   free_first - the free keys; the paid key only if they fail, so a
     *                customer is never left waiting
     *   free_only  - never the paid key (simulator and teaching, even a
     *                document or a photo inside them)
     *
     * @var list<string>
     */
    private static array $purposes = [];

    public static function for(?string $purpose, callable $callback): mixed
    {
        if ($purpose === null || $purpose === '') {
            return $callback();
        }

        self::$purposes[] = $purpose;

        try {
            return $callback();
        } finally {
            array_pop(self::$purposes);
        }
    }

    public static function purpose(): ?string
    {
        return self::$purposes === [] ? null : self::$purposes[array_key_last(self::$purposes)];
    }

    public static function pool(): string
    {
        if (array_intersect(self::$purposes, ['simulator', 'teaching']) !== []) {
            return 'free_only';
        }

        $purpose = self::purpose() ?? 'other';

        return (string) (config('gemini.key_pools.'.$purpose) ?? self::DEFAULT_POOLS[$purpose] ?? 'free_first');
    }

    /** Used when the config cache predates config('gemini.key_pools'). */
    private const DEFAULT_POOLS = [
        'reply' => 'paid', 'document' => 'paid', 'voice' => 'paid', 'work' => 'paid',
        'simulator' => 'free_only', 'teaching' => 'free_only',
    ];

    /** Simulator and teaching conversations - never a real customer. */
    public static function isTestPhone(?string $phone): bool
    {
        return str_starts_with((string) $phone, 'sim-');
    }

    /**
     * Atomically select a model and reserve its request/token allowance.
     *
     * Counters are incremented before the external request is sent. This keeps
     * concurrent workers from selecting the same apparently-unused allowance.
     */
    public function reserveAvailableModel(
        ?string $preferredModelCode = null,
        int $estimatedTokens = 0,
        ?bool $embedding = null,
        array $excludedIds = [],
        string $provider = 'gemini'
    ): ?GeminiApiKeyModel {
        $estimatedTokens = max(0, $estimatedTokens);

        return DB::transaction(function () use (
            $preferredModelCode,
            $estimatedTokens,
            $embedding,
            $excludedIds,
            $provider
        ) {
            $now = now();
            // The pools split Gemini's free and paid keys. GPT has no free
            // keys, so the simulator may spend a GPT key too (owner 2026-10-04).
            $pool = $provider === 'gemini' ? self::pool() : 'any';
            $paidKeyIds = $pool === 'paid' ? $this->livePaidKeyIds($preferredModelCode, $embedding, $provider) : [];

            $model = GeminiApiKeyModel::query()
                ->when($excludedIds !== [], fn ($query) => $query->whereNotIn('id', $excludedIds))
                ->when($paidKeyIds !== [], fn ($query) => $query->whereIn('gemini_api_key_id', $paidKeyIds))
                ->when($pool === 'free_only', fn ($query) => $query->whereHas('apiKey', fn ($key) => $key->where('is_paid', false)))
                // free_first: free keys before the paid one, whatever their priority
                ->when($pool === 'free_first', fn ($query) => $query->orderByRaw(
                    '(select is_paid from gemini_api_keys where gemini_api_keys.id = gemini_api_key_models.gemini_api_key_id)'
                ))
                ->where('provider', $provider)
                ->where('is_active', true)
                ->whereColumn('requests_today', '<', 'rpd_limit')
                ->where(function ($query) use ($now) {
                    $query->whereNull('minute_window_started_at')
                        ->orWhere('minute_window_started_at', '<=', $now->copy()->subMinute())
                        ->orWhereColumn('requests_this_minute', '<', 'rpm_limit');
                })
                ->where(function ($query) use ($now, $estimatedTokens) {
                    $query->whereNull('second_window_started_at')
                        ->orWhere('second_window_started_at', '<=', $now->copy()->subSecond())
                        ->orWhereRaw('(tokens_this_second + ?) <= tps_limit', [$estimatedTokens]);
                })
                ->where(function ($query) use ($now) {
                    $query->whereNull('cooldown_until')
                        ->orWhere('cooldown_until', '<=', $now);
                })
                ->whereHas('apiKey', function ($query) use ($now, $provider) {
                    $query->where('provider', $provider)
                        ->where('is_active', true)
                        ->where(function ($query) use ($now) {
                            $query->whereNull('cooldown_until')
                                ->orWhere('cooldown_until', '<=', $now);
                        });
                })
                ->when($preferredModelCode, function ($query) use ($preferredModelCode) {
                    $query->where('model_code', $preferredModelCode);
                })
                ->when(! is_null($embedding), function ($query) use ($embedding) {
                    $query->where('is_embedding', $embedding);
                })
                ->orderBy('priority')
                ->orderBy('requests_today')
                ->orderBy('requests_this_minute')
                ->orderByRaw('CASE WHEN last_used_at IS NULL THEN 0 ELSE 1 END')
                ->orderBy('last_used_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $model) {
                return null;
            }

            if (! $model->minute_window_started_at
                || $model->minute_window_started_at->lte($now->copy()->subMinute())) {
                $model->requests_this_minute = 0;
                $model->minute_window_started_at = $now;
            }

            if (! $model->second_window_started_at
                || $model->second_window_started_at->lte($now->copy()->subSecond())) {
                $model->tokens_this_second = 0;
                $model->second_window_started_at = $now;
            }

            $model->requests_today++;
            $model->requests_this_minute++;
            $model->tokens_this_second += $estimatedTokens;
            $model->last_used_at = $now;
            $model->last_error = null;
            $model->save();

            GeminiApiKey::query()
                ->whereKey($model->gemini_api_key_id)
                ->update([
                    'last_used_at' => $now,
                    'last_error' => null,
                ]);

            return $model->fresh(['apiKey']);
        }, 3);
    }

    /**
     * Owner 2026-10-02: replies go on the paid key only; the free keys work
     * only once it is finished. A paid key still counts while it sits in a
     * short cooldown (a busy second must not move the bot to a free key) -
     * it is finished when it is switched off, its row for this model is off
     * (a 403 from Google disables it) or its quota is gone for the day.
     *
     * @return list<int>
     */
    private function livePaidKeyIds(?string $preferredModelCode, ?bool $embedding, string $provider): array
    {
        $soon = now()->addMinutes(10);

        return GeminiApiKeyModel::query()
            ->where('provider', $provider)
            ->where('is_active', true)
            ->whereColumn('requests_today', '<', 'rpd_limit')
            ->where(fn ($query) => $query->whereNull('cooldown_until')->orWhere('cooldown_until', '<=', $soon))
            ->when($preferredModelCode, fn ($query) => $query->where('model_code', $preferredModelCode))
            ->when(! is_null($embedding), fn ($query) => $query->where('is_embedding', $embedding))
            ->whereHas('apiKey', fn ($query) => $query->where('is_paid', true)->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('cooldown_until')->orWhere('cooldown_until', '<=', $soon)))
            ->distinct()
            ->pluck('gemini_api_key_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @deprecated Use reserveAvailableModel() so availability and counters are atomic.
     */
    public function getAvailableModel(?string $preferredModelCode = null, int $estimatedTokens = 0, ?bool $embedding = null): ?GeminiApiKeyModel
    {
        return $this->reserveAvailableModel($preferredModelCode, $estimatedTokens, $embedding);
    }

    /**
     * $estimatedTokens is what reserveAvailableModel() already added to
     * tokens_this_second before dispatch (mb_strlen/4, optimistic for
     * Arabic). $usedTokens is the real usageMetadata.totalTokenCount from
     * the response. Reconciling the difference here means the TPS window
     * reflects real usage instead of a permanently-approximate estimate
     * (AI_WHATSAPP_BOT_MEMORY_INTELLIGENCE_AUDIT.md §15.3 G-2).
     */
    /**
     * Owner 2026-10-02: every call's tokens and cost, for the AI costs page.
     * Only a paid key costs money; a free one is logged at zero.
     */
    public function recordUsage(GeminiApiKeyModel $model, array $json, string $source): void
    {
        try {
            $usage = (array) ($json['usageMetadata'] ?? []);
            $prompt = (int) ($usage['promptTokenCount'] ?? 0);
            $cached = (int) ($usage['cachedContentTokenCount'] ?? 0);
            $output = (int) ($usage['candidatesTokenCount'] ?? 0);
            $thoughts = (int) ($usage['thoughtsTokenCount'] ?? 0);
            $paid = (bool) GeminiApiKey::whereKey($model->gemini_api_key_id)->value('is_paid');
            $cost = 0.0;

            if ($paid && ($price = \App\Models\AiModelPrice::where('model_code', $model->model_code)->first())) {
                $cost = (max(0, $prompt - $cached) * $price->input_per_million
                    + $cached * $price->cached_per_million
                    + ($output + $thoughts) * $price->output_per_million) / 1_000_000;
            }

            \App\Models\AiUsageLog::create([
                'gemini_api_key_id' => $model->gemini_api_key_id,
                'model_code' => $model->model_code,
                'source' => self::purpose() ?? $source,
                'is_paid' => $paid,
                'input_tokens' => $prompt,
                'cached_tokens' => $cached,
                'output_tokens' => $output,
                'thoughts_tokens' => $thoughts,
                'cost_usd' => round($cost, 6),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('AI usage log failed', ['error' => $e->getMessage()]);
        }
    }

    public function markUsed(GeminiApiKeyModel $model, int $usedTokens = 0, int $estimatedTokens = 0): void
    {
        $delta = $usedTokens - $estimatedTokens;

        if ($delta !== 0) {
            DB::transaction(function () use ($model, $delta) {
                $fresh = GeminiApiKeyModel::query()->whereKey($model->id)->lockForUpdate()->first();

                if ($fresh) {
                    $fresh->tokens_this_second = max(0, $fresh->tokens_this_second + $delta);
                    $fresh->last_used_at = now();
                    $fresh->last_error = null;
                    $fresh->save();
                }
            });

            return;
        }

        $model->update([
            'last_used_at' => now(),
            'last_error' => null,
        ]);
    }

    public function markError(GeminiApiKeyModel $model, string $error, int $cooldownSeconds = 60): void
    {
        $model->update([
            'last_error' => mb_substr($error, 0, 2000),
            'cooldown_until' => now()->addSeconds($cooldownSeconds),
        ]);
    }

    /**
     * reserveAvailableModel() increments requests_today/requests_this_minute
     * BEFORE the HTTP call, so a network exception or a 5xx that produced
     * no answer was permanently burning daily/minute quota on calls that
     * never actually reached (or were served by) Gemini - self-inflicted
     * exhaustion on top of any real rate limiting (see
     * AI_WHATSAPP_BOT_MEMORY_INTELLIGENCE_AUDIT.md §15.3 G-1). Deliberately
     * NOT called for 429/quota responses - those did consume real quota on
     * Google's side and must stay counted.
     */
    public function refundReservation(GeminiApiKeyModel $model): void
    {
        DB::transaction(function () use ($model) {
            $fresh = GeminiApiKeyModel::query()->whereKey($model->id)->lockForUpdate()->first();

            if (! $fresh) {
                return;
            }

            $fresh->requests_today = max(0, $fresh->requests_today - 1);
            $fresh->requests_this_minute = max(0, $fresh->requests_this_minute - 1);
            $fresh->save();
        });
    }

    public function markDailyLimitFinished(GeminiApiKeyModel $model): void
    {
        $resetTimezone = config('gemini.rate_limits.daily_reset_timezone', config('app.timezone'));
        $resetAt = Carbon::now($resetTimezone)->addDay()->startOfDay();

        $model->update([
            'requests_today' => $model->rpd_limit,
            'cooldown_until' => $resetAt,
            'last_error' => 'Daily request limit reached.',
        ]);

        app(GeminiAlertService::class)->modelExhaustedAlert($model);
    }

    public function refreshWindows(): void
    {
        GeminiApiKeyModel::query()
            ->where(function ($q) {
                $q->whereNull('minute_window_started_at')
                    ->orWhere('minute_window_started_at', '<=', now()->subMinute());
            })
            ->update([
                'requests_this_minute' => 0,
                'minute_window_started_at' => now(),
            ]);

        GeminiApiKeyModel::query()
            ->where(function ($q) {
                $q->whereNull('second_window_started_at')
                    ->orWhere('second_window_started_at', '<=', now()->subSecond());
            })
            ->update([
                'tokens_this_second' => 0,
                'second_window_started_at' => now(),
            ]);
    }

    public function resetDailyUsage(): void
    {
        GeminiApiKeyModel::query()->update([
            'requests_today' => 0,
            'requests_this_minute' => 0,
            'tokens_this_second' => 0,
            'minute_window_started_at' => now(),
            'second_window_started_at' => now(),
            'cooldown_until' => null,
            'last_error' => null,
        ]);
    }
    public function markRateLimited(
        GeminiApiKeyModel $model,
        string $error = 'Rate limit / quota exceeded',
        bool $dailyLimit = false,
        int $cooldownSeconds = 60
    ): void {
        if ($dailyLimit) {
            $this->markDailyLimitFinished($model);

            return;
        }

        $model->update([
            'cooldown_until' => now()->addSeconds(max(1, $cooldownSeconds)),
            'last_error' => mb_substr($error, 0, 2000),
        ]);
    }
}
