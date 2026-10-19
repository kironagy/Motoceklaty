<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One upload on a form link and what the OCR/AI check said about it. */
class FormLinkFile extends Model
{
    protected $fillable = ['form_link_id', 'field_key', 'media_id', 'check_status', 'detected_type_key', 'extracted', 'issues', 'message'];

    protected $casts = ['extracted' => 'array', 'issues' => 'array'];

    public function link(): BelongsTo
    {
        return $this->belongsTo(FormLink::class, 'form_link_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(MessageMedia::class, 'media_id');
    }
}
