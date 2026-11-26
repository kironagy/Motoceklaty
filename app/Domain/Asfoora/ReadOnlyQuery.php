<?php

namespace App\Domain\Asfoora;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-11-26: «عصفورة» answers any question about the system, so the AI may write its own SQL.
 * Only reading, ever:
 *  - one SELECT / WITH statement, no comments, nothing that writes, locks, sleeps or reads files;
 *  - it runs inside a READ ONLY transaction (MySQL) / with query_only on (a merchant's SQLite), rolled back;
 *  - a time limit, and at most MAX_ROWS rows come back;
 *  - secrets never leave: tables of keys, tokens, sessions and passwords are refused, and any column
 *    named like a password, token or API key is dropped from the result.
 */
class ReadOnlyQuery
{
    public const MAX_ROWS = 300;

    /** tables that hold secrets or machinery - never read */
    public const HIDDEN_TABLES = [
        'gemini_api_keys', 'gemini_api_key_models', 'aman_tokens', 'password_reset_tokens', 'push_subscriptions',
        'sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches', 'users', 'migrations',
        'agent_settings', 'env_import_map', 'asfoora_chats', 'asfoora_messages', 'asfoora_memories', 'merchants',
    ];

    /**
     * Tables left out of the description sent with every question (the bot's own machinery and the
     * public website) - each call carried ~37k characters of schema. They can still be queried.
     */
    private const UNDESCRIBED = '/^(ai_|gemini_|teaching_|bot_lessons|agent_|business_memories|eligibility_rules|requirement_fields|document_types|customer_types|customer_attributes|dynamic_form|form_link_answers|form_link_files|legacy_status_mappings|application_(data|documents|events|requirements)|answers|ads|posts|post_comments|sliders|site_settings|newsletter_subscribers|partners|team_members|products|offers|client_reviews|contact_messages|motorcycle_images|message_media|whatsapp_message_jobs|daily_task_files|staff_login_logs|sms_logs|installment_calculators|permission_templates|dashboard_settings|aman_recipes)/';

    /** columns named like this are never returned */
    private const SECRET_COLUMN = '/^(password|remember_token|api_key|token|token_hash|auth_token|public_key|qr_code|device_hash)$/i';

    /** @return array{columns: list<string>, rows: list<array<string, mixed>>, truncated: bool} */
    public static function run(string $sql, int $maxRows = self::MAX_ROWS, int $timeoutMs = 8000): array
    {
        $sql = self::check($sql);
        $connection = DB::connection();
        $sqlite = $connection->getDriverName() === 'sqlite';
        // the server runs MariaDB: its time limit is max_statement_time, in seconds
        $maria = ! $sqlite && str_contains((string) $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION), 'MariaDB');
        $limitVar = $maria ? 'max_statement_time' : 'max_execution_time';
        $rows = [];
        $truncated = false;

        if ($sqlite) {
            $connection->statement('PRAGMA query_only = ON');
        } else {
            $connection->statement("SET SESSION {$limitVar} = ".($maria ? max(1, intdiv($timeoutMs, 1000)) : max(1000, $timeoutMs)));
            $connection->statement('START TRANSACTION READ ONLY');
        }

        try {
            foreach ($connection->cursor($sql) as $row) {
                if (count($rows) >= $maxRows) {
                    $truncated = true;
                    break;
                }
                $rows[] = array_filter((array) $row, fn ($k) => ! preg_match(self::SECRET_COLUMN, (string) $k), ARRAY_FILTER_USE_KEY);
            }
        } finally {
            if ($sqlite) {
                $connection->statement('PRAGMA query_only = OFF');
            } else {
                $connection->statement('ROLLBACK');
                $connection->statement("SET SESSION {$limitVar} = 0");
            }
        }

        return ['columns' => array_keys($rows[0] ?? []), 'rows' => $rows, 'truncated' => $truncated];
    }

    /** Throws \InvalidArgumentException (in words the AI can act on) when the statement is not a plain read. */
    public static function check(string $sql): string
    {
        $sql = trim($sql);
        $sql = rtrim($sql, "; \n\t");

        if ($sql === '') {
            throw new \InvalidArgumentException('Empty query.');
        }
        if (preg_match('/--|#|\/\*/', $sql)) {
            throw new \InvalidArgumentException('Comments are not allowed in the query.');
        }
        if (str_contains($sql, ';')) {
            throw new \InvalidArgumentException('Only one statement is allowed.');
        }
        if (! preg_match('/^\s*(select|with)\b/i', $sql)) {
            throw new \InvalidArgumentException('Only SELECT (or WITH ... SELECT) queries are allowed.');
        }

        // string literals may hold any word (a customer named "Update"); look at the rest only
        $code = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'|\"(?:[^\"\\\\]|\\\\.)*\"/s", "''", $sql);

        if (preg_match('/\b(insert|update|delete|replace|drop|alter|create|truncate|rename|grant|revoke|lock|unlock|call|handler|load_file|load|outfile|dumpfile|sleep|benchmark|get_lock|release_lock|attach|detach|pragma|vacuum|set|execute|prepare|deallocate|kill|shutdown|flush|reset|purge|analyze|optimize|repair|install|uninstall|savepoint|commit|rollback|start|begin)\b/i', $code, $m)) {
            throw new \InvalidArgumentException('"'.$m[1].'" is not allowed - read only.');
        }
        if (preg_match('/\binto\b/i', $code)) {
            throw new \InvalidArgumentException('SELECT ... INTO is not allowed.');
        }
        if (preg_match('/\b(information_schema|mysql|performance_schema|sys|sqlite_master|sqlite_schema)\b/i', $code)) {
            throw new \InvalidArgumentException('System tables are not allowed; the schema is in your instructions.');
        }
        foreach (self::HIDDEN_TABLES as $table) {
            if (preg_match('/\b'.preg_quote($table, '/').'\b/i', $code)) {
                throw new \InvalidArgumentException("The table {$table} is private.");
            }
        }
        if (preg_match('/\b(password|remember_token|api_key|token_hash|auth_token|public_key|qr_code|device_hash)\b/i', $code, $m)) {
            throw new \InvalidArgumentException("The column {$m[1]} is private.");
        }

        return $sql;
    }

    /** The tables the AI may read and their columns, as compact text for its instructions. */
    public static function schemaText(): string
    {
        $connection = DB::connection();

        return Cache::remember('asfoora_schema_v4_'.$connection->getName().'_'.$connection->getDatabaseName(), 3600, function () {
            $lines = [];
            $database = DB::connection()->getDriverName() === 'mysql' ? DB::connection()->getDatabaseName() : null;
            foreach (Schema::getTables() as $table) {
                $name = $table['name'];
                // MySQL lists the tables of every database on the server
                if ($database !== null && isset($table['schema']) && $table['schema'] !== $database) {
                    continue;
                }
                if (in_array($name, self::HIDDEN_TABLES, true) || str_starts_with($name, 'sqlite_') || preg_match(self::UNDESCRIBED, $name)) {
                    continue;
                }
                $columns = array_filter(Schema::getColumns($name), fn ($c) => ! preg_match(self::SECRET_COLUMN, $c['name']));
                // only the types that change how a column is queried (dates, json) - the rest is noise in every call
                $lines[] = $name.'('.implode(', ', array_map(fn ($c) => $c['name'].(preg_match('/date|time|json/i', (string) $c['type_name']) ? ' '.strtolower(preg_replace('/\(.*$/', '', (string) $c['type_name'])) : ''), $columns)).')';
            }

            return implode("\n", $lines);
        });
    }
}
