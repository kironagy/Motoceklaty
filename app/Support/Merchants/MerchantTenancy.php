<?php

namespace App\Support\Merchants;

use App\Models\Merchant;
use App\Models\Staff;
use App\Support\Access;
use Illuminate\Support\Facades\DB;

/**
 * Each merchant has a SQLite database of its own (config merchants.path/{id}.sqlite) with the same
 * tables as the main one. On the merchant's host the default connection becomes `merchant`, so every
 * page, widget and request number of the dashboard works on that showroom's data only.
 */
class MerchantTenancy
{
    public const CONNECTION = 'merchant';

    protected static ?Merchant $current = null;

    public static function mainConnection(): string
    {
        return (string) config('merchants.main_connection', 'sqlite');
    }

    /** The merchant whose dashboard this request is on, or null on the main site. */
    public static function current(): ?Merchant
    {
        return static::$current;
    }

    public static function databasePath(Merchant $merchant): string
    {
        return rtrim((string) config('merchants.path'), '/').'/'.$merchant->id.'.sqlite';
    }

    /** The merchant one label under the base domain, e.g. aqsa.motogate-eg.com; null for any other host. */
    public static function subdomainOf(string $host): ?string
    {
        $base = strtolower((string) config('merchants.base_domain'));
        $host = strtolower($host);

        if ($base === '' || ! str_ends_with($host, '.'.$base)) {
            return null;
        }

        $sub = substr($host, 0, -strlen('.'.$base));

        if ($sub === '' || str_contains($sub, '.') || in_array($sub, (array) config('merchants.reserved', []), true)) {
            return null;
        }

        return $sub;
    }

    /** Points the `merchant` connection at this merchant's file. */
    public static function connect(Merchant $merchant): void
    {
        config(['database.connections.'.self::CONNECTION.'.database' => static::databasePath($merchant)]);
        DB::purge(self::CONNECTION);
        static::addMySqlFunctions(DB::connection(self::CONNECTION)->getPdo());
    }

    /**
     * The few MySQL functions the dashboard's raw queries call (staff ranking, transfer log, expenses),
     * taught to SQLite so the same pages work on a merchant's database. SQLite's json_extract already
     * returns plain text, so json_unquote only passes it on.
     */
    protected static function addMySqlFunctions(\PDO $pdo): void
    {
        $date = fn ($v) => $v === null ? null : strtotime((string) $v);
        $functions = [
            'json_unquote' => [fn ($v) => $v, 1],
            'year' => [fn ($v) => ($t = $date($v)) ? (int) date('Y', $t) : null, 1],
            'month' => [fn ($v) => ($t = $date($v)) ? (int) date('n', $t) : null, 1],
            'day' => [fn ($v) => ($t = $date($v)) ? (int) date('j', $t) : null, 1],
            'hour' => [fn ($v) => ($t = $date($v)) ? (int) date('G', $t) : null, 1],
            'unix_timestamp' => [fn ($v = null) => $v === null ? time() : $date($v), -1],
            'field' => [fn ($needle, ...$list) => ($i = array_search($needle, $list)) === false ? 0 : $i + 1, -1],
        ];

        foreach ($functions as $name => [$callback, $args]) {
            $pdo instanceof \Pdo\Sqlite
                ? $pdo->createFunction($name, $callback, $args)
                : $pdo->sqliteCreateFunction($name, $callback, $args);
        }
    }

    /** For the rest of this request everything reads and writes this merchant's database. */
    public static function activate(Merchant $merchant): void
    {
        static::connect($merchant);
        DB::setDefaultConnection(self::CONNECTION);
        static::$current = $merchant;
    }

    /** Runs $callback against this merchant's database, then puts the previous default back. */
    public static function run(Merchant $merchant, callable $callback): mixed
    {
        $default = DB::getDefaultConnection();
        $previous = static::$current;

        static::connect($merchant);
        DB::setDefaultConnection(self::CONNECTION);

        try {
            return $callback();
        } finally {
            DB::setDefaultConnection($default);
            DB::purge(self::CONNECTION);
            static::$current = $previous;
            if ($previous) {
                static::connect($previous);
            }
        }
    }

    /**
     * Creates the merchant's database when missing, brings its tables up to date (SchemaMirror) and keeps its login
     * in step: name, email, password (when given) and the pages it may open, with every action on each.
     */
    public static function provision(Merchant $merchant, ?string $password = null, ?string $previousEmail = null): void
    {
        $path = static::databasePath($merchant);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        if (! file_exists($path)) {
            touch($path);
        }

        $permissions = static::permissionsFor($merchant);

        static::run($merchant, function () use ($merchant, $password, $previousEmail, $permissions) {
            SchemaMirror::sync(DB::connection(static::mainConnection()), DB::connection(self::CONNECTION));

            $staff = Staff::query()->where('email', $previousEmail ?: $merchant->email)->first()
                ?? Staff::query()->where('email', $merchant->email)->first()
                ?? new Staff;

            $staff->forceFill([
                'name' => $merchant->name,
                'email' => $merchant->email,
                'is_hitler' => false,
                'role_id' => null,
                'permissions' => $permissions,
            ]);

            if (filled($password)) {
                $staff->password = $password;
            }

            $staff->save();
        });
    }

    /** Every action of every page the owner opened for this merchant. */
    public static function permissionsFor(Merchant $merchant): array
    {
        $tabs = Access::tabs();

        return collect((array) $merchant->pages)
            ->filter(fn ($key) => isset($tabs[$key]))
            ->mapWithKeys(fn ($key) => [$key => array_keys($tabs[$key]['abilities'])])
            ->all();
    }
}
