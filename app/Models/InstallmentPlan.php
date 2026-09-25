<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallmentPlan extends Model
{
    protected $fillable = [
        'installment_system_id',
        'months',
        'interest_percent',
        'is_active',
    ];

    protected $casts = [
        'months' => 'integer',
        'interest_percent' => 'float',
        'is_active' => 'boolean',
    ];

    public function installmentSystem(): BelongsTo
    {
        return $this->belongsTo(InstallmentSystem::class);
    }
}
