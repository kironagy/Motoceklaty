<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageMedia extends Model
{
    protected $fillable = [
        'message_id',
        'media_type',
        'mime',
        'disk',
        'path',
        'size',
        'sha256',
        'original_filename',
        'analysis',
    ];

    protected $casts = [
        'analysis' => 'array',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(WhatsappMessage::class, 'message_id');
    }
}
