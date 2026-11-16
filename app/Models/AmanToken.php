<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AmanToken extends Model
{
    protected $fillable = ['staff_id', 'token_hash', 'device', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /** Creates a token for this staff member and returns the plain value (shown once). */
    public static function issue(Staff $staff, ?string $device = null): string
    {
        $plain = 'amn_'.Str::random(40);
        static::create(['staff_id' => $staff->id, 'token_hash' => hash('sha256', $plain), 'device' => $device ? Str::limit($device, 115) : null]);

        return $plain;
    }
}
