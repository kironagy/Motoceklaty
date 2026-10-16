<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeBonus extends Model
{
    protected $fillable = [
        'staff_id',
        'amount',
        'bonus_date',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'bonus_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function ($bonus) {
            if (! $bonus->created_by && auth()->check()) {
                $bonus->created_by = auth()->id();
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
