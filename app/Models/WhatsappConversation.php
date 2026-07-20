<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappConversation extends Model
{
    protected $fillable = [
        'whatsapp_bot_id',
        'phone',
        'status',

        'last_machine_id',
        'last_machine_ids',
        'last_topic',
        'pending_question',
        'context_payload',

        'current_step',
        'last_intent',
        'customer_job_type',
    ];

    protected $casts = [
        'last_machine_ids' => 'array',
        'context_payload' => 'array',
    ];

    public function messages()
    {
        return $this->hasMany(WhatsappMessage::class);
    }

    public function lastMachine()
    {
        return $this->belongsTo(Machine::class, 'last_machine_id');
    }
}
