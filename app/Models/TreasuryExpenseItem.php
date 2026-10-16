<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
class TreasuryExpenseItem extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
    protected $fillable = [
        'treasury_expense_id',
        'created_by',
        'expense_item',
        'amount',
    ];

    public function treasuryExpense()
    {
        return $this->belongsTo(TreasuryExpense::class);
    }

    public function creator()
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }
}
