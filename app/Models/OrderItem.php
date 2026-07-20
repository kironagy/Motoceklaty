<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $table = 'order_items';

    protected $fillable = [
        'order_id',
        'color',
        'chassis_image',
        'engine_image',
        'stock_item_id',
    ];

    // 🔗 الطلبية
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    // 🔗 وحدة المخزن (اختياري)
    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }
}

