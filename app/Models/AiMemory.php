<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiMemory extends Model
{
    protected $fillable = [
        'title',
        'content',
        'template_replies',
        'is_active',
        'sort',
    ];

    protected $casts = [
        'template_replies' => 'array',
        'is_active' => 'boolean',
    ];
    
protected static function booted(): void
{
    static::saved(function () {
        if (class_exists(\App\Services\Ai\MemoryToonIndexBuilder::class)) {
            app(\App\Services\Ai\MemoryToonIndexBuilder::class)->rebuild();
        }
    });

    static::deleted(function () {
        if (class_exists(\App\Services\Ai\MemoryToonIndexBuilder::class)) {
            app(\App\Services\Ai\MemoryToonIndexBuilder::class)->rebuild();
        }
    });
}
}
