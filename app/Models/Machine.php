<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Machine extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'brand_id',
        'display_image',
        'colors',
        'cash_price',
        'installment_price',
        'installment_systems',
        'features',
        'type',         // 🔹 نوع المكنة (عادي / عرض)
        'old_price',    // 🔹 السعر قبل الخصم
        'new_price',    // 🔹 السعر بعد الخصم
        'aliases',
    ];


    protected $casts = [
        'colors' => 'array',
        'installment_systems' => 'array',
        'features' => 'array',
        'aliases' => 'array',
    ];

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }
    // App\Models\Machine.php

    public function scopeOffers($query)
    {
        return $query->where('type', 'offer');
    }

    public function installmentSystem()
    {
        return $this->belongsTo(InstallmentSystem::class, 'installment_system_id');
    }
}
