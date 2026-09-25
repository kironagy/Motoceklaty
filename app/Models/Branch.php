<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $fillable = [
        'name',
        'governorate',
        'city',
        'address',
        'map_url',
        'latitude',
        'longitude',
        'phones',
        'working_hours',
        'services',
        'is_active',
        'sort',
    ];

    protected $casts = [
        'phones' => 'array',
        'working_hours' => 'array',
        'services' => 'array',
        'is_active' => 'boolean',
    ];
}
