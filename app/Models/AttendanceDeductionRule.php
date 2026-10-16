<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceDeductionRule extends Model
{
    protected $fillable = [
        'staff_id',
        'from_minute',
        'to_minute',
        'deduction_days',
    ];

    protected $casts = [
        'deduction_days' => 'decimal:2',
    ];

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }
}
