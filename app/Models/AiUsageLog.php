<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['gemini_api_key_id', 'model_code', 'source', 'is_paid', 'input_tokens', 'cached_tokens', 'output_tokens', 'thoughts_tokens', 'cost_usd'];

    protected $casts = [
        'is_paid' => 'boolean',
        'cost_usd' => 'float',
    ];

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(GeminiApiKey::class, 'gemini_api_key_id');
    }
}
