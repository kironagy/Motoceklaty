<?php

namespace App\Models;

use App\Support\SiteContent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Post extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'author_name',
        'cover_image',
        'excerpt',
        'body',
        'tags',
        'meta_description',
        'is_published',
        'allow_comments',
        'published_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'is_published' => 'boolean',
        'allow_comments' => 'boolean',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Post $post) {
            if (blank($post->slug)) {
                $post->slug = static::uniqueSlug($post->title, $post->id);
            }
            if ($post->is_published && ! $post->published_at) {
                $post->published_at = now();
            }
        });
    }

    /** Arabic titles: Str::slug drops Arabic letters, so keep them. */
    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = trim(preg_replace('/[^\p{Arabic}\p{L}\p{N}]+/u', '-', mb_strtolower($title)), '-') ?: Str::random(8);
        $base = mb_substr($base, 0, 120);
        $slug = $base;
        $i = 2;

        while (static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    public function comments()
    {
        return $this->hasMany(PostComment::class);
    }

    public function approvedComments()
    {
        return $this->comments()->where('is_approved', true)->latest();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->orderByDesc('published_at');
    }

    public function coverUrl(): ?string
    {
        return SiteContent::url($this->cover_image);
    }

    public function excerptText(int $limit = 160): string
    {
        return $this->excerpt ?: Str::limit(trim(strip_tags((string) $this->body)), $limit);
    }
}
