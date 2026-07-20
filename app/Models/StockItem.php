<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockItem extends Model
{
    protected $fillable = [
        'stock_group_id',
        'color',
        'chassis_image',
        'engine_image',
        'customer_name',
        'customer_phone',
        'id_front_image',
        'id_back_image',
        'remaining_amount',
    ];

    protected $casts = [
        'remaining_amount' => 'decimal:2',
    ];

    public function group()
    {
        return $this->belongsTo(StockGroup::class, 'stock_group_id');
    }
    protected static function booted(): void
{
    static::created(function (self $item) {
        $item->group?->refreshAvailableQuantity();
    });

    static::deleted(function (self $item) {
        $item->group?->refreshAvailableQuantity();
    });
}

}

