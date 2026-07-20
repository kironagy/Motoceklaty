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
        'last_used_at',
        'cooldown_until',
        'last_error',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
        'cooldown_until' => 'datetime',
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
}
