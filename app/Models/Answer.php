<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Answer extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'machine_name',
        'chassis_image',
        'engine_image',
        'id_front_image',
        'id_back_image',
        'received_from_raed',
        'delivered_to_customer',
        'remaining_amount',
    ];

    protected $casts = [
        'received_from_raed' => 'boolean',
        'delivered_to_customer' => 'boolean',
        'remaining_amount' => 'decimal:2',
    ];
}

