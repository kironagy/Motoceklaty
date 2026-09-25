<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAttribute extends Model
{
    protected $fillable = [
        'customer_id',
        'field_key',
        'value',
        'source',
        'status',
        'evidence_message_id',
        'document_id',
        'verified_at',
    ];

    /** See ApplicationData - always encrypted, sensitive or not. */
    protected $casts = [
        'value' => 'encrypted',
        'verified_at' => 'datetime',
    ];

    /**
     * Keeps the identity lookup hash in step with the value on every write
     * path (AI tools, document pipeline, staff dashboard).
     */
    protected static function booted(): void
    {
        static::saving(function (self $row) {
            if (in_array($row->field_key, \App\Support\IdentityLookup::identityFieldKeys(), true)) {
                $row->lookup_hash = \App\Support\IdentityLookup::hash((string) $row->value);
            }
        });
    }


    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
