<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequirementField extends Model
{
    protected $fillable = [
        'key',
        'label',
        'data_type',
        'enum_options',
        'scope',
        'is_sensitive',
        'description_for_ai',
        'is_active',
    ];

    protected $casts = [
        'enum_options' => 'array',
        'is_sensitive' => 'boolean',
        'is_active' => 'boolean',
    ];
}
