<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Owner 2026-11-26: changes «عصفورة» prepared (from Aman's sheet) that wait for the owner's «نفّذ». */
class AsfooraAction extends Model
{
    protected $fillable = ['asfoora_chat_id', 'kind', 'payload', 'status', 'applied_by', 'applied_at', 'result'];

    protected $casts = ['payload' => 'array', 'result' => 'array', 'applied_at' => 'datetime'];
}
