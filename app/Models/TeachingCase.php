<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeachingCase extends Model
{
    protected $fillable = ['teaching_session_id', 'bot_lesson_id', 'history', 'bad_reply', 'expectation', 'must_contain', 'scope_stage', 'is_active', 'last_result', 'last_reply', 'last_reason', 'last_run_at'];

    protected $casts = [
        'history' => 'array',
        'must_contain' => 'array',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
    ];
}
