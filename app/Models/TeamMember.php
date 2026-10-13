<?php

namespace App\Models;

use App\Support\SiteContent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TeamMember extends Model
{
    protected $fillable = ['name', 'role', 'image', 'show_on_about', 'is_active', 'sort'];

    protected $casts = [
        'show_on_about' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort')->orderBy('id');
    }

    public function imageUrl(): ?string
    {
        return SiteContent::url($this->image);
    }
}
