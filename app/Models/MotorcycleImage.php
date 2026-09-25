<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MotorcycleImage extends Model
{
    protected $fillable = [
        'machine_id',
        'color',
        'path',
        'is_display',
        'sort',
    ];

    protected $casts = [
        'is_display' => 'boolean',
    ];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
