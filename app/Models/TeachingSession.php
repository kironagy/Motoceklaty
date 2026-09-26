<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeachingSession extends Model
{
    protected $fillable = ['conversation_id', 'target_message_id', 'after_message_id', 'owner_text', 'understanding', 'question', 'result', 'status', 'created_by'];

    protected $casts = ['result' => 'array'];

    public function changes(): HasMany
    {
        return $this->hasMany(TeachingChange::class);
    }

    public function cases(): HasMany
    {
        return $this->hasMany(TeachingCase::class);
    }
}
