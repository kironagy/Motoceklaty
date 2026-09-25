<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'whatsapp_bot_id',
        'jid',
        'lid_jid',
        'phone',
        'push_name',
    ];

    public function whatsappBot(): BelongsTo
    {
        return $this->belongsTo(WhatsappBot::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(WhatsappConversation::class);
    }
}
