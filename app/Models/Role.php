<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A named set of dashboard tabs and actions, see App\Support\Access. */
class Role extends Model
{
    protected $fillable = ['name', 'permissions'];

    protected $casts = [
        'permissions' => 'array',
    ];

    public function staff()
    {
        return $this->hasMany(Staff::class);
    }
}
