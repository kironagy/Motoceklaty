<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationDocument extends Model
{
    protected $fillable = [
        'application_id',
        'document_type_id',
        'media_id',
        'party',
        'status',
        'detected_type_key',
        'expected_type_key',
        'confidence',
        'extracted',
        'issues',
        'attempts',
    ];

    protected $casts = [
        'confidence' => 'float',
        'extracted' => 'encrypted:array',
        'issues' => 'array',
        'attempts' => 'integer',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(MessageMedia::class, 'media_id');
    }
}
