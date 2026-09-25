<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InstallmentSystem extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'pricing_mode',
        'plans',
        'administrative_fees', // ✅ أضفنا العمود الجديد هنا
        'minimum_down_payment',
        'is_active',
        'priority',
        'customer_type_ids',
        'governorates',
        'max_financed_amount',
    ];

    protected $casts = [
        'plans' => 'array',
        'minimum_down_payment' => 'float',
        'is_active' => 'boolean',
        'priority' => 'integer',
        'customer_type_ids' => 'array',
        'governorates' => 'array',
        'max_financed_amount' => 'float',
    ];

    /**
     * Whether the customer meets this system's conditions. An unknown
     * customer type passes a type restriction (the price is only a quote
     * until he says what he works); a governorate restriction needs the
     * governorate to be known - "امان - الجيزة" is not for everyone.
     */
    public function acceptsCustomer(?int $customerTypeId, ?string $governorate): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $types = array_map('intval', $this->customer_type_ids ?? []);

        if ($types !== [] && $customerTypeId !== null && ! in_array($customerTypeId, $types, true)) {
            return false;
        }

        $governorates = $this->governorates ?? [];

        return $governorates === [] || ($governorate !== null && in_array($governorate, $governorates, true));
    }

    /** DEC-01: 'standard' or 'zero_fees' - see InstallmentCalculator. */
    public function isZeroFees(): bool
    {
        return $this->pricing_mode === 'zero_fees';
    }

    /**
     * Queryable projection of the `plans` JSON, kept in sync by
     * InstallmentSystemObserver. The calculator and tools read this, never
     * the JSON (plan T12 constraint: no formula/data duplication drift).
     */
    public function installmentPlans()
    {
        return $this->hasMany(InstallmentPlan::class);
    }

    /**
     * Fixed: previously assumed a non-existent machines.installment_system_id
     * column. Machines relate to systems through the machine_installment_system
     * pivot (T12), same as Machine::installmentSystems().
     */
    public function machines()
    {
        return $this->belongsToMany(Machine::class, 'machine_installment_system');
    }
}
