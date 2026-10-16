<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One use of green points: canceling a red / black, or a bonus (App\Domain\Attendance\AttendancePoints). */
class AttendancePointExchange extends Model
{
    protected $fillable = [
        'staff_id',
        'kind',
        'canceled_point_id',
        'greens_used',
        'amount',
        'bonus_id',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function canceledPoint()
    {
        return $this->belongsTo(AttendancePoint::class, 'canceled_point_id');
    }

    public function bonus()
    {
        return $this->belongsTo(EmployeeBonus::class, 'bonus_id');
    }

    public function creator()
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }
}
