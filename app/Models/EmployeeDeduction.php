<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Days taken off an employee's salary by hand; amount = days x (monthly salary / 30). */
class EmployeeDeduction extends Model
{
    protected $fillable = [
        'staff_id',
        'days',
        'amount',
        'deduction_date',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'days' => 'decimal:2',
        'amount' => 'decimal:2',
        'deduction_date' => 'date',
    ];

    public static function dayRate(?Staff $staff): float
    {
        $salary = (float) ($staff?->monthly_salary ?? 0);

        return $salary > 0 ? $salary / 30 : 0;
    }

    protected static function booted(): void
    {
        static::saving(function (self $deduction) {
            if (! $deduction->created_by && auth()->check()) {
                $deduction->created_by = auth()->id();
            }

            // Re-price only when the employee or the days change, so an old deduction keeps its value.
            if (! $deduction->exists || $deduction->isDirty(['staff_id', 'days'])) {
                $deduction->amount = round((float) $deduction->days * static::dayRate(Staff::find($deduction->staff_id)), 2);
            }
        });
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function creator()
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }
}
