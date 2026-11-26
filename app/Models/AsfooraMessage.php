<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsfooraMessage extends Model
{
    protected $fillable = ['asfoora_chat_id', 'role', 'content', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function chat()
    {
        return $this->belongsTo(AsfooraChat::class, 'asfoora_chat_id');
    }
}
