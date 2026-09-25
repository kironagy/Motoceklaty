<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegacyStatusMapping extends Model
{
    protected $fillable = ['legacy_status', 'application_status'];
}
