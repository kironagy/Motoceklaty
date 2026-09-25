<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Handoff extends Model
{
    protected $fillable = [
        'conversation_id',
        'reason',
        'note',
        'source',
        'opened_at',
        'closed_at',
        'closed_by',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsappConversation::class, 'conversation_id');
    }

    public function closedByStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'closed_by');
    }
}
