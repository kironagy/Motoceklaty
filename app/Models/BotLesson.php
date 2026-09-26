<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotLesson extends Model
{
    protected $fillable = ['title', 'rule', 'fixed_facts', 'example_context', 'example_reply', 'scope_customer_types', 'scope_stage', 'priority', 'is_active', 'revision', 'teaching_session_id', 'source_message_id', 'created_by'];

    protected $casts = [
        'fixed_facts' => 'array',
        'scope_customer_types' => 'array',
        'is_active' => 'boolean',
    ];
}
