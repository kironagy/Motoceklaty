<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerProfile extends Model
{
    protected $fillable = [
        'phone',
        'name',
        'job_type',
        'income_category',
        'last_machine_id',
        'preferred_months',
        'last_deposit',
        'applications_count',
        'last_application_at',
        'last_seen_at',
    ];

    protected $casts = [
        'preferred_months' => 'integer',
        'last_deposit' => 'decimal:2',
        'applications_count' => 'integer',
        'last_application_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function lastMachine(): BelongsTo
    {
        return $this->belongsTo(Machine::class, 'last_machine_id');
    }
}
