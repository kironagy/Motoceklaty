<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AiMemory extends Model
{
    protected $fillable = [
        'title',
        'content',
        'category',
        'scope',
        'applicable_intents',
        'keywords',
        'priority',
        'template_replies',
        'is_active',
        'sort',
    ];

    protected $casts = [
        'template_replies' => 'array',
        'applicable_intents' => 'array',
        'keywords' => 'array',
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => static::clearMemoryCaches());
        static::deleted(fn () => static::clearMemoryCaches());
    }

    /**
     * Both the full-context cache (AiMemoryContextBuilder, 5 min TTL) and
     * the relevance-scoring cache (AiMemoryResolver, 1 min TTL) read from
     * this table but only refresh on their own TTL — an edit in Filament
     * was invisible to the AI for up to 5 minutes with no way to force it.
     * A stale-cache-invalidation hook already existed here referencing
     * App\Services\Ai\MemoryToonIndexBuilder, but that class was never
     * created, so it silently did nothing.
     */
    private static function clearMemoryCaches(): void
    {
        app(\App\Services\AiMemoryContextBuilder::class)->clearCache();
        Cache::forget('active_ai_memories_for_laravel');
    }
}
