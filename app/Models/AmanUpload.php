<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmanUpload extends Model
{
    public const DONE = ['uploaded', 'rejected_before'];

    protected $fillable = [
        'installment_request_id', 'staff_id', 'type', 'recipe_version', 'status',
        'aman_ref', 'message', 'log', 'claimed_at', 'finished_at',
    ];

    protected $casts = ['log' => 'array', 'claimed_at' => 'datetime', 'finished_at' => 'datetime'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(InstallmentRequest::class, 'installment_request_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
