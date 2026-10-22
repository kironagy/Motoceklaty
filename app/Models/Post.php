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

    /**
     * The body in the approved article layout: text before the first heading,
     * then one section per heading (h2/h3/h4 from the editor), as motogate-2 renders it.
     *
     * @return array{intro: string, sections: list<array{title: string, html: string}>}
     */
    public function designSections(): array
    {
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?><div id="post-body">' . $this->body . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $document->getElementById('post-body');
        $intro = '';
        $sections = [];
        foreach ($root ? iterator_to_array($root->childNodes) : [] as $node) {
            if ($node instanceof \DOMElement && in_array(strtolower($node->tagName), ['h2', 'h3', 'h4'], true)) {
                $sections[] = ['title' => trim($node->textContent), 'html' => ''];
                continue;
            }
            $html = $document->saveHTML($node);
            if ($sections === []) {
                $intro .= $html;
            } else {
                $sections[array_key_last($sections)]['html'] .= $html;
            }
        }

        return ['intro' => trim($intro), 'sections' => $sections];
    }
}
