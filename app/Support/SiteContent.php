<?php

namespace App\Support;

use App\Models\SiteSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Read/write side of the website's editable content.
 *
 *   site('home.hero.title')      -> saved value, else SiteDefaults
 *   SiteContent::save('home', $formState)
 *
 * Saved keys always win over defaults - including an empty value, which is
 * how an admin hides a line. Lists (repeaters) are replaced wholesale, never
 * merged item-by-item.
 */
class SiteContent
{
    public const CACHE_KEY = 'site_content.saved.v1';

    protected static ?array $merged = null;

    public static function all(): array
    {
        if (static::$merged !== null) {
            return static::$merged;
        }

        return static::$merged = static::merge(SiteDefaults::all(), static::saved());
    }

    public static function group(string $group): array
    {
        return static::all()[$group] ?? [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return Arr::get(static::all(), $key, $default);
    }

    public static function save(string $group, array $data): void
    {
        SiteSetting::updateOrCreate(['key' => $group], ['value' => static::normalize($data)]);
        static::flush();
    }

    public static function flush(): void
    {
        Cache::forget(static::CACHE_KEY);
        static::$merged = null;
    }

    /** Public URL for anything a content field can hold. */
    public static function url(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (preg_match('~^(https?:)?//~i', $path)) {
            return $path;
        }

        if (str_starts_with($path, 'site/v2-defaults/')) {
            return asset('motogate-v2/assets/images/' . static::encodePath(substr($path, strlen('site/v2-defaults/'))));
        }

        if (str_starts_with($path, 'site/defaults/')) {
            return asset('motogate/images/' . static::encodePath(substr($path, strlen('site/defaults/'))));
        }

        return asset('storage/' . static::encodePath(ltrim($path, '/')));
    }

    /** "Trio Bikes.png" -> "Trio%20Bikes.png", segment by segment. */
    protected static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', rawurldecode($path))));
    }

    /**
     * Mirror the design's bundled images onto the public disk so the
     * dashboard's FileUpload fields can preview the default image before
     * anyone replaces it. Copies only what is missing.
     */
    public static function publishDefaults(): void
    {
        $v2 = public_path('motogate-v2/assets/images');
        if (File::isDirectory($v2)) {
            foreach (File::allFiles($v2) as $file) {
                $target = 'site/v2-defaults/' . $file->getRelativePathname();
                if (! Storage::disk('public')->exists($target)) {
                    Storage::disk('public')->put($target, File::get($file->getPathname()));
                }
            }
        }
        $source = public_path('motogate/images');

        if (! File::isDirectory($source)) {
            return;
        }

        $disk = Storage::disk('public');

        foreach (File::allFiles($source) as $file) {
            $target = 'site/defaults/' . str_replace('\\', '/', $file->getRelativePathname());

            if (! $disk->exists($target)) {
                $disk->put($target, File::get($file->getPathname()));
            }
        }
    }

    protected static function saved(): array
    {
        try {
            if (! Schema::hasTable('site_settings')) {
                return [];
            }

            return Cache::rememberForever(static::CACHE_KEY, fn () => SiteSetting::query()
                ->pluck('value', 'key')
                ->map(fn ($value) => is_array($value) ? $value : (json_decode((string) $value, true) ?: []))
                ->all());
        } catch (\Throwable) {
            return [];
        }
    }

    public static function merge(array $defaults, array $saved): array
    {
        foreach ($saved as $key => $value) {
            $default = $defaults[$key] ?? null;

            $defaults[$key] = is_array($value) && is_array($default) && ! array_is_list($default) && ! array_is_list($value)
                ? static::merge($default, $value)
                : $value;
        }

        return $defaults;
    }

    /** Repeater state arrives keyed by UUIDs; store plain lists. */
    protected static function normalize(array $data): array
    {
        $isUuidKeyed = $data !== [] && collect(array_keys($data))
            ->every(fn ($key) => is_string($key) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $key));

        if ($isUuidKeyed) {
            $data = array_values($data);
        }

        return array_map(fn ($value) => is_array($value) ? static::normalize($value) : $value, $data);
    }
}
