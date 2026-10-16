<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Target extends Model
{
    protected $fillable = [
        'warehouse_id',
        'target_count',
        'from_date',
        'to_date',
        'is_active',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function soldCount(): int
    {
        return Letter::query()
            ->whereHas('inventory', function ($query) {
                $query->where('warehouse_id', $this->warehouse_id);
            })
            ->whereBetween('created_at', [
                now()->startOfMonth(),
                now()->endOfMonth(),
            ])
            ->count();
    }
}
