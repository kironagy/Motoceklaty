<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
class InventoryItem extends Model
{
   use LogsActivity;
    protected $fillable = [
        'inventory_id',
        'chassis_image',
        'engine_image',
        'color',
'customer_id_image',
        'customer_name',
        'national_id',
        'phone',
        'address',
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
    ];

    public function inventory()
    {
        return $this->belongsTo(Inventory::class);
    }
    
     

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
