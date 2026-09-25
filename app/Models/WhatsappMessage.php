<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappMessage extends Model
{
    protected $fillable = [
        'whatsapp_conversation_id',
        'whatsapp_bot_id',
        'wa_message_id',
        'direction',
        'sender_type',
        'type',
        'text',
        'transcript',
        'transcription_status',
        'message',
        'payload',
        'quoted_message_id',
        'turn_id',
        'delivery_status',
        'metadata',
    ];

    protected $casts = [
        'payload' => 'array',
        'metadata' => 'array',
    ];

    public function conversation()
    {
        return $this->belongsTo(WhatsappConversation::class, 'whatsapp_conversation_id');
    }

    public function quotedMessage()
    {
        return $this->belongsTo(self::class, 'quoted_message_id');
    }

    public function media()
    {
        return $this->hasMany(MessageMedia::class, 'message_id');
    }
}

