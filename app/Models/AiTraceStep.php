<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiTraceStep extends Model
{
    protected $fillable = [
        'trace_id',
        'turn_id',
        'seq',
        'kind',
        'tool_name',
        'permission',
        'args_hash',
        'args_redacted',
        'result_redacted',
        'result_code',
        'latency_ms',
        'input_tokens',
        'output_tokens',
    ];

    protected $casts = [
        'args_redacted' => 'array',
        'result_redacted' => 'array',
    ];

    public function trace(): BelongsTo
    {
        return $this->belongsTo(AiTrace::class, 'trace_id');
    }
}
