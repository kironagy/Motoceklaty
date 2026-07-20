<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InstallmentSystem extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'plans',
        'administrative_fees', // ✅ أضفنا العمود الجديد هنا
    ];

    protected $casts = [
        'plans' => 'array',
    ];

    public function machines()
    {
        return $this->hasMany(Machine::class);
    }
}
