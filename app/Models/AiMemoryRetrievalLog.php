<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiMemoryRetrievalLog extends Model
{
    protected $fillable = [
        'whatsapp_conversation_id',
        'message_excerpt',
        'intent',
        'candidate_memory_ids',
        'selected_memory_ids',
        'scores',
        'retrieval_method',
        'fell_back_to_full_dump',
    ];

    protected $casts = [
        'candidate_memory_ids' => 'array',
        'selected_memory_ids' => 'array',
        'scores' => 'array',
        'fell_back_to_full_dump' => 'boolean',
    ];
}
