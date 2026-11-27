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
        'letter_image',
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
        'sold_by',
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

    /** The employee who made the sale from the inventory page. */
    public function seller()
    {
        return $this->belongsTo(Staff::class, 'sold_by');
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

    /** Owner 2026-11-27: «تم التنفيذ» with «صورة الجواب» sends it to the customer on WhatsApp, once. */
    protected static function booted(): void
    {
        static::saved(function (self $letter) {
            if ($letter->status === \App\Jobs\SendLetterReadyWhatsapp::READY_STATUS && filled($letter->letter_image) && ! $letter->ready_sent_at
                && ($letter->wasChanged('status') || $letter->wasChanged('letter_image') || $letter->wasRecentlyCreated)) {
                \App\Jobs\SendLetterReadyWhatsapp::dispatch($letter->id)->afterCommit();
            }
        });
    }
}
