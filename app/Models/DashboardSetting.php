<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Owner settings edited from the dashboard settings page (attendance points, sidebar layout). */
class DashboardSetting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    protected $casts = ['value' => 'array'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::rememberForever("dashboard_setting.{$key}", fn () => static::query()->where('key', $key)->value('value'));

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return $value ?? $default;
    }

    public static function put(string $key, mixed $value, ?int $by = null): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $by]);
        Cache::forget("dashboard_setting.{$key}");
    }
}
