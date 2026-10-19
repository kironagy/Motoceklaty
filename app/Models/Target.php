<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * A showroom's monthly sales target. The counter is the month's letters (each letter is a sold machine)
 * of that showroom, not returned. It restarts every month; a target with dates only counts inside them.
 */
class Target extends Model
{
    protected $fillable = [
        'warehouse_id',
        'target_count',
        'from_date',
        'to_date',
        'is_active',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'is_active' => 'boolean',
    ];

    /** Counts already worked out on this row, by month - the list asks several columns for the same one. */
    protected array $soldCache = [];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** The days of the month this target counts: the month, cut to the target's own dates. Null = not in it. */
    public function periodIn(Carbon $month): ?array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        if ($this->from_date && $this->from_date->gt($from)) {
            $from = $this->from_date->copy()->startOfDay();
        }

        if ($this->to_date && $this->to_date->lt($to)) {
            $to = $this->to_date->copy()->endOfDay();
        }

        return $from->lte($to) ? [$from, $to] : null;
    }

    public function soldCount(?Carbon $month = null): int
    {
        $month ??= now();

        return $this->soldCache[$month->format('Y-m')] ??= $this->countSold($month);
    }

    protected function countSold(Carbon $month): int
    {
        if (! $period = $this->periodIn($month)) {
            return 0;
        }

        return Letter::query()
            ->where('is_returned', false)
            // The showroom the letter was written in; old letters only have it through their stock.
            ->where(fn ($q) => $q
                ->where('warehouse_id', $this->warehouse_id)
                ->orWhere(fn ($q) => $q->whereNull('warehouse_id')
                    ->whereHas('inventory', fn ($i) => $i->where('warehouse_id', $this->warehouse_id))))
            ->whereBetween('created_at', $period)
            ->count();
    }
}
