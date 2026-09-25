<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessMemory extends Model
{
    protected $fillable = [
        'key',
        'category',
        'title',
        'content',
        'priority',
        'is_pinned',
        'scope_customer_types',
        'scope_application_statuses',
        'is_active',
        'updated_by',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'is_active' => 'boolean',
        'scope_customer_types' => 'array',
        'scope_application_statuses' => 'array',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'updated_by');
    }

    /** Matches the char/4 estimate used elsewhere (e.g. GeminiClient). */
    public function estimatedTokens(): int
    {
        return (int) ceil(mb_strlen((string) $this->content) / 4);
    }
}
