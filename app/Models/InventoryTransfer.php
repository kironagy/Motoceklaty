<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One machine moved from one showroom (warehouse) to another. */
class InventoryTransfer extends Model
{
    protected $fillable = ['inventory_item_id', 'machine_id', 'from_warehouse_id', 'to_warehouse_id', 'staff_id', 'note'];

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function from()
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function to()
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }
}
