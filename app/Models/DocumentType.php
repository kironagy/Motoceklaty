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
        'optional_fields',
        'validation_rules',
        'is_active',
    ];

    protected $casts = [
        'accepted_mimes' => 'array',
        'extraction_fields' => 'array',
        'optional_fields' => 'array',
        'validation_rules' => 'array',
        'is_active' => 'boolean',
    ];

    /** @return string[] required fields first, then the ones read only when printed */
    public function allFields(): array
    {
        return array_values(array_unique(array_merge(
            (array) ($this->extraction_fields ?? []),
            (array) ($this->optional_fields ?? []),
        )));
    }
}
