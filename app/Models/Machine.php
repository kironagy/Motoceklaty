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
        'is_featured',
        'video_url',
    ];


    protected $casts = [
        'colors' => 'array',
        'installment_systems' => 'array',
        'features' => 'array',
        'aliases' => 'array',
        'specifications' => 'array',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
    ];

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function motorcycleImages()
    {
        return $this->hasMany(MotorcycleImage::class);
    }

    /* ---------------- Website presentation ---------------- */

    public function categoryLabel(): ?string
    {
        return config('agent.catalog.categories', [])[$this->category] ?? null;
    }

    public function displayImageUrl(): ?string
    {
        return \App\Support\SiteContent::url($this->display_image);
    }

    /** Feature titles, whatever shape the repeater saved them in. */
    public function featureTitles(): array
    {
        return collect($this->features ?? [])
            ->map(fn ($f) => is_array($f) ? ($f['title'] ?? implode(' ', $f)) : $f)
            ->map(fn ($f) => trim((string) $f))
            ->filter()
            ->values()
            ->all();
    }

    /** Spec values formatted "key: value" for the spec grid. */
    public function specificationLines(): array
    {
        return collect($this->specifications ?? [])
            ->map(fn ($value, $key) => is_string($key) && $key !== '' ? "{$key}: {$value}" : (string) $value)
            ->filter()
            ->values()
            ->all();
    }

    /** Specs as [label, value] rows for the detail page table. */
    public function specificationRows(): array
    {
        return collect($this->specifications ?? [])
            ->map(fn ($value, $key) => [
                'label' => is_string($key) ? trim($key) : null,
                'value' => trim(is_array($value) ? implode('، ', $value) : (string) $value),
            ])
            ->filter(fn ($row) => $row['value'] !== '')
            ->values()
            ->all();
    }

    /**
     * One entry per colour for the detail page gallery + colour picker.
     * Falls back to the display image when no colours were uploaded.
     */
    public function colorVariants(): array
    {
        $variants = collect($this->colors ?? [])
            ->map(function ($color) {
                $images = collect([$color['color_display'] ?? null])
                    ->merge($color['images'] ?? [])
                    ->filter()
                    ->map(fn ($path) => \App\Support\SiteContent::url($path))
                    ->values()
                    ->all();

                $hex = $color['color'] ?? null;

                return [
                    'hex' => $hex ?: '#cccccc',
                    // Same naming the WhatsApp agent uses when no name was picked.
                    'name' => ($color['color_name'] ?? null) ?: ($hex ? \App\Support\ColorNamer::name($hex) : null),
                    'images' => $images,
                ];
            })
            ->filter(fn ($variant) => $variant['images'] !== [])
            ->values()
            ->all();

        if ($variants === [] && $this->display_image) {
            $variants[] = ['hex' => null, 'name' => null, 'images' => [$this->displayImageUrl()]];
        }

        return $variants;
    }

    public function youtubeEmbedUrl(): ?string
    {
        if (! $this->video_url) {
            return null;
        }

        if (preg_match('~(?:youtu\.be/|v=|embed/|shorts/)([\w-]{11})~', $this->video_url, $m)) {
            return 'https://www.youtube.com/embed/' . $m[1];
        }

        return $this->video_url;
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
