<?php

namespace App\Services;

use App\Models\GeminiApiKey;
use App\Models\GeminiApiKeyModel;
use Illuminate\Support\Facades\DB;

class GeminiKeyManager
{
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

            $model = GeminiApiKeyModel::query()
                ->when($excludedIds !== [], fn ($query) => $query->whereNotIn('id', $excludedIds))
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
     * @deprecated Use reserveAvailableModel() so availability and counters are atomic.
     */
    public function getAvailableModel(?string $preferredModelCode = null, int $estimatedTokens = 0, ?bool $embedding = null): ?GeminiApiKeyModel
    {
        return $this->reserveAvailableModel($preferredModelCode, $estimatedTokens, $embedding);
    }

    public function markUsed(GeminiApiKeyModel $model, int $usedTokens = 0): void
    {
        // Request/token allowance was already reserved before dispatch.
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

    public function markDailyLimitFinished(GeminiApiKeyModel $model): void
    {
        $model->update([
            'requests_today' => $model->rpd_limit,
            'cooldown_until' => now()->endOfDay(),
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
