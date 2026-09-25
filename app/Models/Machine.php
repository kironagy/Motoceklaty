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
        'is_active',
        'availability',
        'cc',
        'model_year',
        'category',
        'description',
        'specifications',
    ];


    protected $casts = [
        'colors' => 'array',
        'installment_systems' => 'array',
        'features' => 'array',
        'aliases' => 'array',
        'specifications' => 'array',
        'is_active' => 'boolean',
    ];

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function motorcycleImages()
    {
        return $this->hasMany(MotorcycleImage::class);
    }

    public function scopeOffers($query)
    {
        return $query->where('type', 'offer');
    }

    /**
     * Structured relation for T12 once it creates the
     * machine_installment_system pivot. Until then, CatalogService reads
     * the existing installment_systems JSON column directly - that data
     * already exists and this relation would just error on a missing
     * table if queried now.
     */
    public function installmentSystems()
    {
        return $this->belongsToMany(InstallmentSystem::class, 'machine_installment_system');
    }

    /** @return int[] */
    public function installmentSystemIds(): array
    {
        return array_map('intval', $this->installment_systems ?? []);
    }
}
