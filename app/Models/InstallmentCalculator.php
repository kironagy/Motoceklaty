<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InstallmentCalculator extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'machine_id',
        'installment_system_id',
        'months',
        'down_payment',
        'machine_cash_price',
        'machine_installment_price',
        'interest_percent',
        'admin_fees_percent',
        'admin_fees_amount',
        'total_with_interest',
        'monthly_installment',
    ];

    protected $casts = [
        'down_payment' => 'decimal:2',
        'machine_cash_price' => 'decimal:2',
        'machine_installment_price' => 'decimal:2',
        'interest_percent' => 'decimal:2',
        'admin_fees_percent' => 'decimal:2',
        'admin_fees_amount' => 'decimal:2',
        'total_with_interest' => 'decimal:2',
        'monthly_installment' => 'decimal:2',
    ];

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function installmentSystem()
    {
        return $this->belongsTo(InstallmentSystem::class);
    }
}
