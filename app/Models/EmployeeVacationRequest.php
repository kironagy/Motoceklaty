<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeVacationRequest extends Model
{
    protected $fillable = [
        'staff_id',
        'from_date',
        'to_date',
        'vacation_type',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'admin_notes',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
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
