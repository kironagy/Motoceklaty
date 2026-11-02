<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeminiApiKey extends Model
{
    protected $fillable = [
        'provider',
        'name',
        'api_key',
        'is_active',
        'is_paid',
        'credit_usd',
        'credit_since',
        'last_used_at',
        'cooldown_until',
        'last_error',
        'usage_snapshot',
        'usage_refreshed_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
        'cooldown_until' => 'datetime',
        'is_paid' => 'boolean',
        'credit_since' => 'datetime',
        'usage_snapshot' => 'array',
        'usage_refreshed_at' => 'datetime',
    ];

    public function models(): HasMany
    {
        return $this->hasMany(GeminiApiKeyModel::class, 'gemini_api_key_id');
    }

    public function isGemini(): bool
    {
        return $this->provider === 'gemini';
    }

    public function isGroq(): bool
    {
        return $this->provider === 'groq';
    }

    public function isBormaa(): bool
    {
        return $this->provider === 'bormaa';
    }
}
