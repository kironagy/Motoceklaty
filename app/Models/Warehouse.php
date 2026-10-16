<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $fillable = ['name', 'branch_id'];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function staff()
    {
        return $this->hasMany(Staff::class);
    }

    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }
}
