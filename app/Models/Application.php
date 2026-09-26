<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    protected $fillable = [
        'customer_id',
        'origin_conversation_id',
        'customer_type_id',
        'machine_id',
        'installment_plan_id',
        'down_payment',
        'status',
        'submitted_at',
        'installment_request_id',
        'last_activity_at',
        'staff_request',
    ];

    protected $casts = [
        'down_payment' => 'float',
        'submitted_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'staff_request' => 'array',
    ];

    /** Statuses that count as "still open" for the active-application policy (T13 §3). */
    public const ACTIVE_STATUSES = ['collecting', 'submitted', 'under_review', 'needs_more_info'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerType(): BelongsTo
    {
        return $this->belongsTo(CustomerType::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function installmentPlan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class);
    }

    public function installmentRequest(): BelongsTo
    {
        return $this->belongsTo(InstallmentRequest::class);
    }

    public function data(): HasMany
    {
        return $this->hasMany(ApplicationData::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApplicationEvent::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }
}
