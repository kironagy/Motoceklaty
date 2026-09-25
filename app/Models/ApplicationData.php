<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationData extends Model
{
    protected $table = 'application_data';

    protected $fillable = [
        'application_id',
        'field_key',
        'party',
        'value',
        'source',
        'status',
        'issue_code',
        'evidence_message_id',
        'document_id',
    ];

    /**
     * Always encrypted at rest, sensitive or not - simpler and strictly
     * safer than a per-row conditional cast, and satisfies T13's
     * "sensitive values encrypted at rest" acceptance criterion either way.
     */
    protected $casts = [
        'value' => 'encrypted',
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


    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function evidenceMessage(): BelongsTo
    {
        return $this->belongsTo(WhatsappMessage::class, 'evidence_message_id');
    }
}
