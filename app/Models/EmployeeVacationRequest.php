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

        // Approved days are vacation in the attendance; a change of status or dates, or a delete, re-syncs them.
        static::saved(function (EmployeeVacationRequest $request) {
            if ($request->wasRecentlyCreated || $request->wasChanged(['status', 'from_date', 'to_date', 'staff_id'])) {
                \App\Domain\Attendance\VacationAttendance::sync($request);
            }
        });
        static::deleted(fn (EmployeeVacationRequest $request) => \App\Domain\Attendance\VacationAttendance::sync($request, deleted: true));
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
