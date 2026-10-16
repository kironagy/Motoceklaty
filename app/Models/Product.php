<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
protected $fillable = ['name', 'machine_id'];

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }
    public function inventories()
{
    return $this->hasMany(Inventory::class);
}
}
