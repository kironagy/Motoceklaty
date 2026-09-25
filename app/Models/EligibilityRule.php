<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EligibilityRule extends Model
{
    protected $fillable = ['customer_type_id', 'rule_type', 'params', 'is_active'];

    protected $casts = [
        'params' => 'array',
        'is_active' => 'boolean',
    ];

    public function customerType(): BelongsTo
    {
        return $this->belongsTo(CustomerType::class);
    }
}
