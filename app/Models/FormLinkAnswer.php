<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormLinkAnswer extends Model
{
    protected $fillable = ['form_link_id', 'field_key', 'value'];

    // national ids and phones: encrypted at rest like application_data
    protected $casts = ['value' => 'encrypted:json'];

    public function link(): BelongsTo
    {
        return $this->belongsTo(FormLink::class, 'form_link_id');
    }
}
