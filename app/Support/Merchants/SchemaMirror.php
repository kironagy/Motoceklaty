<?php

namespace App\Support\Merchants;

use Illuminate\Database\Connection;

/**
 * Copies the main database's tables (structure only, no rows) into a merchant's SQLite file.
 * The migrations hold MySQL-only statements (ALTER ... MODIFY ENUM), so a merchant's database is
 * built from the live schema instead: missing tables are created, missing columns added, and
 * nothing is ever dropped, so running it again after a deploy only brings new tables and columns.
 */
class SchemaMirror
{
    /** Tables a showroom never needs. */
    protected const SKIP = ['merchants'];

    public static function sync(Connection $from, Connection $to): void
    {
        $source = $from->getSchemaBuilder();
        $existing = collect($to->getSchemaBuilder()->getTableListing(null, false))->flip();

        foreach ($source->getTableListing($from->getDatabaseName(), false) as $table) {
            if (in_array($table, self::SKIP, true)) {
                continue;
            }

            $columns = $source->getColumns($table);
            $indexes = $source->getIndexes($table);

            if (! $existing->has($table)) {
                $to->statement(static::createTable($table, $columns, $indexes));

                foreach ($indexes as $index) {
                    if (! $index['primary']) {
                        $to->statement(static::createIndex($table, $index));
                    }
                }

                continue;
            }

            $have = collect($to->getSchemaBuilder()->getColumns($table))->pluck('name')->flip();

            foreach ($columns as $column) {
                if (! $have->has($column['name'])) {
                    // SQLite can't add a NOT NULL column without a default, so new columns may be empty
                    $to->statement('ALTER TABLE '.static::q($table).' ADD COLUMN '.static::column($column, false, true));
                }
            }
        }
    }

    protected static function createTable(string $table, array $columns, array $indexes): string
    {
        $primary = collect($indexes)->firstWhere('primary', true)['columns'] ?? [];
        $autoId = count($primary) === 1
            && (bool) (collect($columns)->firstWhere('name', $primary[0])['auto_increment'] ?? false);

        $lines = array_map(fn ($c) => static::column($c, $autoId && $c['name'] === $primary[0]), $columns);

        if ($primary && ! $autoId) {
            $lines[] = 'PRIMARY KEY ('.implode(', ', array_map([static::class, 'q'], $primary)).')';
        }

        return 'CREATE TABLE '.static::q($table).' ('.implode(', ', $lines).')';
    }

    protected static function createIndex(string $table, array $index): string
    {
        return 'CREATE '.($index['unique'] ? 'UNIQUE ' : '').'INDEX IF NOT EXISTS '.static::q($table.'__'.$index['name'])
            .' ON '.static::q($table).' ('.implode(', ', array_map([static::class, 'q'], $index['columns'])).')';
    }

    protected static function column(array $c, bool $autoId = false, bool $adding = false): string
    {
        if ($autoId) {
            return static::q($c['name']).' INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        $sql = static::q($c['name']).' '.static::type((string) $c['type_name']);
        $default = static::defaultValue($c['default']);

        if (! $c['nullable'] && ! ($adding && $default === null)) {
            $sql .= ' NOT NULL';
        }

        if ($default !== null) {
            $sql .= ' DEFAULT '.$default;
        }

        return $sql;
    }

    protected static function type(string $mysql): string
    {
        return match (strtolower($mysql)) {
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'year', 'bit' => 'INTEGER',
            'decimal', 'numeric' => 'NUMERIC',
            'float', 'double', 'real' => 'REAL',
            'blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary' => 'BLOB',
            'date' => 'DATE',
            'datetime', 'timestamp' => 'DATETIME',
            'time' => 'TIME',
            default => 'TEXT',
        };
    }

    /** MySQL hands defaults raw (new, 0, CURRENT_TIMESTAMP); MariaDB quotes strings ('new') and says NULL. */
    protected static function defaultValue(mixed $default): ?string
    {
        if ($default === null) {
            return null;
        }

        $default = trim((string) $default);

        if ($default === '') {
            return "''";
        }

        if (strcasecmp($default, 'NULL') === 0) {
            return null;
        }

        if (preg_match('/^current_timestamp(\(\d*\))?$/i', $default)) {
            return 'CURRENT_TIMESTAMP';
        }

        if (is_numeric($default)) {
            return $default;
        }

        if (preg_match("/^'(.*)'$/s", $default, $m)) {
            $default = str_replace("''", "'", $m[1]);
        } elseif (str_contains($default, '(')) {
            return null; // an expression default (uuid(), json_array()) - leave it to the app
        }

        return "'".str_replace("'", "''", $default)."'";
    }

    protected static function q(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
