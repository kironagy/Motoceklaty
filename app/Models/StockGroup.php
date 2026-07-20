<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockGroup extends Model
{
    protected $fillable = [
        'group_name',
        'machine_id',
        'quantity',
        'quantity_available',
    ];

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockItem::class);
    }

    // ✅ تحديث quantity_available تلقائيًا
    public function refreshAvailableQuantity(): void
    {
        $this->updateQuietly([
            'quantity_available' => $this->items()->count(),
        ]);
    }
    public function increaseQuantity(int $by): void
{
    $this->updateQuietly([
        'quantity' => $this->quantity + $by,
    ]);

    $this->refreshAvailableQuantity();
}

public function decreaseQuantity(int $by): void
{
    $newQty = max(0, $this->quantity - $by);

    $this->updateQuietly([
        'quantity' => $newQty,
    ]);

    $this->refreshAvailableQuantity();
}

}

