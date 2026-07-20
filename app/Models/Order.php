<?php

namespace App\Models;
use App\Models\OrderItem;


use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'name',
        'trader_id',
        'stock_group_id',
        'type',
    ];

    protected $casts = [
        'type' => 'string',
    ];

    public function trader()
    {
        return $this->belongsTo(Trader::class);
    }
public function items()
{
    return $this->hasMany(OrderItem::class);
}

    public function group()
    {
        return $this->belongsTo(StockGroup::class, 'stock_group_id');
    }
}

