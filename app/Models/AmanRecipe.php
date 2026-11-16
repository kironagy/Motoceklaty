<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AmanRecipe extends Model
{
    protected $fillable = ['type', 'version', 'steps', 'updated_by'];

    protected $casts = ['steps' => 'array', 'version' => 'integer'];
}
