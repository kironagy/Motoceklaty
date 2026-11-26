<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Owner 2026-11-26: one conversation with «عصفورة», the system's AI assistant. */
class AsfooraChat extends Model
{
    protected $fillable = ['staff_id', 'title'];

    public function messages()
    {
        return $this->hasMany(AsfooraMessage::class)->orderBy('id');
    }
}
