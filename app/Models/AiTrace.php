<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiTrace extends Model
{
    protected $fillable = [
        'conversation_id',
        'turn_id',
        'prompt_version',
        'provider',
        'model',
        'status',
        'context_manifest',
        'final_reply_message_ids',
        'guard_events',
        'error_code',
        'input_tokens',
        'output_tokens',
        'latency_ms',
    ];

    protected $casts = [
        'context_manifest' => 'array',
        'final_reply_message_ids' => 'array',
        'guard_events' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsappConversation::class, 'conversation_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AiTraceStep::class, 'trace_id');
    }
}
