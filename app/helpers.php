<?php

use App\Support\SiteContent;

if (! function_exists('site')) {
    /** Editable website content: site('home.hero.title'). */
    function site(string $key, mixed $default = null): mixed
    {
        return SiteContent::get($key, $default);
    }
}

if (! function_exists('site_url')) {
    /** Public URL for an uploaded or bundled website image/video. */
    function site_url(?string $path): ?string
    {
        return SiteContent::url($path);
    }
}

if (! function_exists('site_link')) {
    /** Menu/button links are saved as "/about" or a full URL. */
    function site_link(?string $url): string
    {
        if (blank($url)) {
            return '#';
        }

        return preg_match('~^(https?:|mailto:|tel:|#|//)~i', $url) ? $url : url($url);
    }
}

if (! function_exists('site_tel')) {
    /** "0102 888 7119" -> "tel:01028887119". */
    function site_tel(?string $phone): string
    {
        return 'tel:' . preg_replace('/[^\d+]/', '', (string) $phone);
    }
}

if (! function_exists('site_text')) {
    /** Fill ":name" placeholders in a saved text. */
    function site_text(?string $text, array $replace = []): string
    {
        // Longest keys first, so ":to" never eats the start of ":total".
        uksort($replace, fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));

        foreach ($replace as $key => $value) {
            $text = str_replace(':' . $key, (string) $value, (string) $text);
        }

        return (string) $text;
    }
}

if (! function_exists('site_feather')) {
    /** "shield", "feather-shield" or "feather feather-shield" -> "feather-shield". */
    function site_feather(?string $icon, string $fallback = 'feather-star'): string
    {
        $name = trim(preg_replace('/^(feather\s+)?(feather-)?/', '', trim((string) $icon)));

        return $name === '' ? $fallback : 'feather-' . $name;
    }
}

if (! function_exists('site_asset')) {
    /** asset() for public/motogate files, cache-busted by modification time. */
    function site_asset(string $path): string
    {
        $file = public_path('motogate/' . $path);

        return asset('motogate/' . $path) . (is_file($file) ? '?v=' . filemtime($file) : '');
    }
}

if (! function_exists('site_whatsapp')) {
    /** wa.me link for an Egyptian number like 01028887119, with an optional message. */
    function site_whatsapp(?string $phone, ?string $text = null): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            $digits = '2' . $digits; // 010... -> 2010...
        }

        return 'https://wa.me/' . $digits . ($text ? '?text=' . rawurlencode($text) : '');
    }
}
