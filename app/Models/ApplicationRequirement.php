<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationRequirement extends Model
{
    protected $fillable = [
        'customer_type_id',
        'requirement_type',
        'requirement_field_id',
        'document_type_id',
        'is_required',
        'condition',
        'sort',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'condition' => 'array',
    ];

    public function customerType(): BelongsTo
    {
        return $this->belongsTo(CustomerType::class);
    }

    public function requirementField(): BelongsTo
    {
        return $this->belongsTo(RequirementField::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }
}
