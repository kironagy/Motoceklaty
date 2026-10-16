<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
class Letter extends Model
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
        'status',
        'inventory_id',
        'inventory_item_id',
        'is_returned',
'returned_at',
        'chassis_image',
        'engine_image',
        'color',
 'warehouse_id',
        'customer_name',
        'national_id',
        'phone',
        'address',
'customer_id_image',
        'cash',
        'limit',
        'admin_fees',
        'paid',
        'remaining',

        'chassis_number',
        'engine_number',
    ];

    protected $casts = [
        'cash' => 'decimal:2',
        'limit' => 'decimal:2',
        'admin_fees' => 'decimal:2',
        'paid' => 'decimal:2',
        'remaining' => 'decimal:2',
            'returned_at' => 'datetime',

    'is_returned' => 'boolean',
    ];

    public function inventory()
    {
        return $this->belongsTo(Inventory::class);
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }
    
    public function scopeNotReturned($query)
{
    return $query->where('is_returned', false);
}
public function warehouse()
{
    return $this->belongsTo(Warehouse::class);
}

public function markAsReturned(): void
{
    $this->update([
        'is_returned' => true,
        'returned_at' => now(),
    ]);
}
}

