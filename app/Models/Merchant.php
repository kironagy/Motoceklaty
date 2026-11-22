<?php

namespace App\Models;

use App\Support\Merchants\MerchantTenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A merchant's showroom (owner 2026-11-23). It always lives in the main database; its dashboard opens
 * on {subdomain}.{base domain} against a database of its own, showing only the pages in `pages`.
 */
class Merchant extends Model
{
    protected $fillable = ['name', 'email', 'showroom_name', 'subdomain', 'logo', 'pages', 'is_active'];

    protected $casts = [
        'pages' => 'array',
        'is_active' => 'boolean',
    ];

    /** Always the main database, also while a merchant's dashboard is open. */
    public function getConnectionName()
    {
        return MerchantTenancy::mainConnection();
    }

    public function url(): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $port = parse_url((string) config('app.url'), PHP_URL_PORT);

        return $scheme.'://'.$this->subdomain.'.'.config('merchants.base_domain').($port ? ':'.$port : '').'/admin';
    }

    public function logoUrl(): ?string
    {
        return $this->logo ? Storage::disk('public')->url($this->logo) : null;
    }
}
