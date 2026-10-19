<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A form the owner builds in the dashboard; customers fill its published version (Dynamic Links). */
class DynamicForm extends Model
{
    protected $fillable = ['name', 'description', 'schema', 'is_bot_default', 'is_active', 'published_version_id'];

    protected $casts = [
        'schema' => 'array',
        'is_bot_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(DynamicFormVersion::class);
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(DynamicFormVersion::class, 'published_version_id');
    }

    /** The form the bot sends: the default one, else the only active published one. */
    public static function forBot(): ?self
    {
        $published = static::query()->where('is_active', true)->whereNotNull('published_version_id');

        return (clone $published)->where('is_bot_default', true)->first() ?? $published->orderBy('id')->first();
    }
}
