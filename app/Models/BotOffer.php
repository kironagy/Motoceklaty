<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Owner 2026-11-19: an offer from "عروض البوت". Live = switched on and inside its dates.
 * - machine: a motorcycle that exists only here (e.g. هوندا كسر زيرو); it is projected onto one
 *   catalog Machine (type = offer) so search, details, photos, installments and applications all work.
 * - interest: a lower yearly rate (e.g. 20% instead of 21% for employees), used by the bot's
 *   installment math and the dashboard calculator alike (App\Domain\Offers\BotOfferService).
 * - general: free text the bot may tell customers (a gift, a lower down payment...).
 */
class BotOffer extends Model
{
    public const KINDS = [
        'machine' => 'مكنة (كسر زيرو / مستعملة)',
        'interest' => 'فايدة أقل',
        'general' => 'عرض عام (كلام)',
    ];

    public const CONDITIONS = [
        'كسر زيرو' => 'كسر زيرو',
        'مستعمل' => 'مستعمل',
        'جديد' => 'جديد',
    ];

    protected $fillable = [
        'kind', 'title', 'details', 'is_active', 'starts_at', 'ends_at', 'priority',
        'machine_id', 'brand_id', 'machine_name', 'condition', 'cash_price', 'installment_price', 'old_price',
        'stock', 'images', 'specifications', 'aliases',
        'yearly_rate', 'customer_type_ids', 'installment_system_ids', 'months', 'machine_ids',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'images' => 'array',
        'specifications' => 'array',
        'aliases' => 'array',
        'customer_type_ids' => 'array',
        'installment_system_ids' => 'array',
        'months' => 'array',
        'machine_ids' => 'array',
        'cash_price' => 'float',
        'installment_price' => 'float',
        'old_price' => 'float',
        'yearly_rate' => 'float',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $offer) {
            app(\App\Domain\Offers\BotOfferService::class)->forget();
            if ($offer->kind === 'machine') {
                app(\App\Domain\Offers\OfferMachineSync::class)->sync($offer);
            }
        });

        static::deleted(function (self $offer) {
            app(\App\Domain\Offers\BotOfferService::class)->forget();
            if ($offer->machine_id) {
                Machine::whereKey($offer->machine_id)->first()?->update(['is_active' => false]);
            }
        });
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function scopeLive(Builder $q): Builder
    {
        $now = now();

        return $q->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    public function isLive(): bool
    {
        return $this->is_active
            && ($this->starts_at === null || $this->starts_at->lte(now()))
            && ($this->ends_at === null || $this->ends_at->gt(now()))
            && ! ($this->kind === 'machine' && $this->stock !== null && $this->stock <= 0);
    }

    /** The plan's rate scaled to its length: 20 a year = 20% on 12 months, 30% on 18, 40% on 24. */
    public function rateFor(int $months): float
    {
        return round((float) $this->yearly_rate * $months / 12, 2);
    }

    /** Does this interest offer cover that system / plan length / customer type / motorcycle? */
    public function covers(int $systemId, int $months, ?int $customerTypeId, ?int $machineId): bool
    {
        $in = fn (?array $list, ?int $value) => empty($list) || ($value !== null && in_array($value, array_map('intval', $list), true));

        return $this->kind === 'interest'
            && $this->yearly_rate !== null
            && $in($this->installment_system_ids, $systemId)
            && $in($this->months, $months)
            && $in($this->customer_type_ids, $customerTypeId)
            && $in($this->machine_ids, $machineId);
    }
}
