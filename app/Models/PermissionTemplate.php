<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A saved set of tabs and actions (same shape as Role::permissions) to tick several at once. */
class PermissionTemplate extends Model
{
    protected $fillable = ['name', 'permissions'];

    protected $casts = [
        'permissions' => 'array',
    ];
}
