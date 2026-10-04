<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One model HTTP attempt (rebuild OBS-001); written by App\Agent\Tracing\AiCalls. */
class AiCall extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'capture' => 'array',
    ];
}
