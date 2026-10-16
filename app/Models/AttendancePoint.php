<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One green / red / black point (App\Domain\Attendance\AttendancePoints). */
class AttendancePoint extends Model
{
    protected $fillable = [
        'staff_id',
        'attendance_id',
        'color',
        'amount',
        'earned_on',
        'status',
        'exchange_id',
        'source',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'earned_on' => 'date',
    ];

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function attendance()
    {
        return $this->belongsTo(Attendance::class);
    }

    public function exchange()
    {
        return $this->belongsTo(AttendancePointExchange::class, 'exchange_id');
    }

    public function creator()
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }
}
