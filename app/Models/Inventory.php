<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
class Inventory extends Model
{
   use LogsActivity;
  

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
protected $fillable = ['warehouse_id', 'product_id'];

public function warehouse()
{
    return $this->belongsTo(Warehouse::class);
}

public function product()
{
    return $this->belongsTo(Product::class);
}

public function items()
{
    return $this->hasMany(InventoryItem::class);
}
}
