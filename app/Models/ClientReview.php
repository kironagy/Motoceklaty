<?php

namespace App\Models;

use App\Support\SiteContent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ClientReview extends Model
{
    protected $fillable = ['name', 'position', 'image', 'review', 'rating', 'is_active', 'sort'];

    protected $casts = [
        'rating' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort')->latest('id');
    }

    public function imageUrl(): ?string
    {
        return SiteContent::url($this->image);
    }
}
