<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffLoginLog extends Model
{
    protected $table = 'staff_login_logs';

    protected $fillable = [
        'staff_id',
        'ip_address',
        'user_agent',
        'device_hash',
        'browser',
        'platform',
        'device_type',
        'logged_in_at',
    ];

    protected $casts = [
        'logged_in_at' => 'datetime',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
