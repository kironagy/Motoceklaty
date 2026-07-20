<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeminiApiKeyModel extends Model
{
    protected $fillable = [
        'gemini_api_key_id',
        'display_name',
        'model_code',
        'category',
        'rpm_limit',
        'rpd_limit',
        'tps_limit',
        'requests_today',
        'requests_this_minute',
        'tokens_this_second',
        'minute_window_started_at',
        'second_window_started_at',
        'last_used_at',
        'cooldown_until',
        'is_active',
        'is_embedding',
        'priority',
        'last_error',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_embedding' => 'boolean',
        'minute_window_started_at' => 'datetime',
        'second_window_started_at' => 'datetime',
        'last_used_at' => 'datetime',
        'cooldown_until' => 'datetime',
    ];

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(GeminiApiKey::class, 'gemini_api_key_id');
    }

    public function getRemainingTodayAttribute(): int
    {
        return max(0, (int) $this->rpd_limit - (int) $this->requests_today);
    }

    public function getRemainingMinuteAttribute(): int
    {
        return max(0, (int) $this->rpm_limit - (int) $this->requests_this_minute);
    }

    public function getRemainingTokensSecondAttribute(): int
    {
        return max(0, (int) $this->tps_limit - (int) $this->tokens_this_second);
    }

    public function getIsAvailableAttribute(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if (! $this->apiKey?->is_active) {
            return false;
        }

        if ($this->requests_today >= $this->rpd_limit) {
            return false;
        }

        if ($this->requests_this_minute >= $this->rpm_limit) {
            return false;
        }

        if ($this->tokens_this_second >= $this->tps_limit) {
            return false;
        }

        if ($this->cooldown_until && now()->lt($this->cooldown_until)) {
            return false;
        }

        return true;
    }
}
