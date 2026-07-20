<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    protected $fillable = [
        'staff_id',
        'checked_in_at',
        'is_late',
        'penalty_days',
        'applied_rule',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
        'is_late' => 'boolean',
        'penalty_days' => 'integer',
        'applied_rule' => 'array',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}

