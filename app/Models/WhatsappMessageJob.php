<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappMessageJob extends Model
{
    protected $fillable = [
        'whatsapp_bot_id',
        'whatsapp_conversation_id',
        'phone',
        'from',
        'reply_jid',
        'message',
        'media_items',
        'payload',
        'status',
        'attempts',
        'locked_at',
        'processed_at',
        'error',
    ];

    protected $casts = [
        'media_items' => 'array',
        'payload' => 'array',
        'locked_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}