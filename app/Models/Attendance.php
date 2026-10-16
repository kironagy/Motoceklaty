<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $fillable = [
        'staff_id',
        'attendance_date',
        'check_in_at',
        'check_out_at',
        'check_in_latitude',
        'check_in_longitude',
        'check_out_latitude',
        'check_out_longitude',
        'late_minutes',
        'deduction_days',
        'deduction_amount',
        'status',
        'notes',
        'overtime_minutes',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'deduction_days' => 'decimal:2',
        'deduction_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // Late / overtime minutes and the day's point follow the check-in and check-out times,
        // from the clock-in widget or an admin's edit.
        static::saving(fn (Attendance $attendance) => \App\Domain\Attendance\AttendancePoints::beforeSave($attendance));
        static::saved(fn (Attendance $attendance) => \App\Domain\Attendance\AttendancePoints::afterSave($attendance));
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function point()
    {
        return $this->hasOne(AttendancePoint::class);
    }
}
