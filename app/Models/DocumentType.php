<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentType extends Model
{
    protected $fillable = [
        'key',
        'label',
        'description_for_ai',
        'accepted_mimes',
        'extraction_fields',
        'validation_rules',
        'is_active',
    ];

    protected $casts = [
        'accepted_mimes' => 'array',
        'extraction_fields' => 'array',
        'validation_rules' => 'array',
        'is_active' => 'boolean',
    ];
}
