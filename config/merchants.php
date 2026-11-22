<?php

// Merchants (owner 2026-11-23): every merchant's showroom opens the dashboard on its own subdomain
// ({subdomain}.{base_domain}) with a database of its own, so its requests and staff start from zero.
return [
    // The site's domain; a host one label under it is a merchant's showroom.
    'base_domain' => env('MERCHANT_BASE_DOMAIN', preg_replace('/^www\./', '', (string) parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST))),

    // Subdomains that are never a merchant's.
    'reserved' => ['www', 'api', 'mail', 'admin', 'webmail', 'ftp', 'cpanel', 'ns1', 'ns2'],

    // The main database's connection (the default one switches to `merchant` on a showroom's host).
    'main_connection' => env('DB_CONNECTION', 'sqlite'),

    // Where each merchant's SQLite database lives (one file per merchant: {id}.sqlite).
    'path' => env('MERCHANT_DB_PATH', storage_path('app/merchants')),
];
