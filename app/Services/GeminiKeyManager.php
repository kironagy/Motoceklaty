<?php

namespace App\Services;

use App\Models\GeminiApiKeyModel;
use Illuminate\Support\Facades\DB;

class GeminiKeyManager
{
    public function getAvailableModel(?string $preferredModelCode = null, int $estimatedTokens = 0, ?bool $embedding = null): ?GeminiApiKeyModel
    {
        $this->refreshWindows();

        return GeminiApiKeyModel::query()
            ->with('apiKey')
            ->where('is_active', true)
            ->whereColumn('requests_today', '<', 'rpd_limit')
            ->whereColumn('requests_this_minute', '<', 'rpm_limit')
            ->whereRaw('(tokens_this_second + ?) < tps_limit', [$estimatedTokens])
            ->where(function ($q) {
                $q->whereNull('cooldown_until')
                    ->orWhere('cooldown_until', '<=', now());
            })
            ->whereHas('apiKey', function ($q) {
                $q->where('is_active', true);
            })
            ->when($preferredModelCode, function ($q) use ($preferredModelCode) {
                $q->where('model_code', $preferredModelCode);
            })
            ->when(! is_null($embedding), function ($q) use ($embedding) {
                $q->where('is_embedding', $embedding);
            })
            ->orderBy('priority')
            ->orderBy('requests_today')
            ->orderBy('requests_this_minute')
            ->first();
    }

    public function markUsed(GeminiApiKeyModel $model, int $usedTokens = 0): void
    {
        DB::transaction(function () use ($model, $usedTokens) {
            $model->refresh();

            $model->increment('requests_today');
            $model->increment('requests_this_minute');

            if ($usedTokens > 0) {
                $model->increment('tokens_this_second', $usedTokens);
            }

            $model->update([
                'last_used_at' => now(),
                'last_error' => null,
            ]);
        });
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
    public function markRateLimited(GeminiApiKeyModel $model, string $error = 'Rate limit / quota exceeded'): void
{
    $model->update([
        'requests_today' => $model->rpd_limit,
        'cooldown_until' => now()->endOfDay(),
        'last_error' => mb_substr($error, 0, 2000),
    ]);
}
}