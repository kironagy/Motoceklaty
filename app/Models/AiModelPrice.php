<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiModelPrice extends Model
{
    protected $fillable = ['model_code', 'input_per_million', 'cached_per_million', 'output_per_million'];

    protected $casts = [
        'input_per_million' => 'float',
        'cached_per_million' => 'float',
        'output_per_million' => 'float',
    ];
}
