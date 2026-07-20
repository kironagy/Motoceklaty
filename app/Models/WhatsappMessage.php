<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappMessage extends Model
{
    protected $fillable = [
        'whatsapp_conversation_id',
        'direction',
        'message',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function conversation()
    {
        return $this->belongsTo(WhatsappConversation::class, 'whatsapp_conversation_id');
    }
}

