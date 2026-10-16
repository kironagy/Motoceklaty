<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeAdvanceRequest extends Model
{
    protected $fillable = [
        'staff_id',
        'amount',
        'request_date',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'admin_notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'request_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($request) {
            if (! $request->staff_id && auth()->check()) {
                $request->staff_id = auth()->id();
            }

            if (! $request->status) {
                $request->status = 'pending';
            }
        });
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(Staff::class, 'reviewed_by');
    }
}
