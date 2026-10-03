<?php

namespace App\Support\Backup;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes a restorable SQL dump of the application database (the legacy
 * "Backup Database" page), in pure PHP — no `mysqldump` binary, which
 * shared hosting often lacks.
 *
 * Secrets that are useless in a backup are left out: session, cache, and
 * queue tables and password-reset tokens are dumped as structure only,
 * and "remember me" tokens are blanked (restoring simply signs everyone
 * out). Password *hashes* are kept — a restore must be able to sign in.
 */
class DatabaseDumper
{
    /**
     * Tables whose rows are never exported.
     */
    public const STRUCTURE_ONLY = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    private const CHUNK = 250;

    public function __construct(private readonly ?Connection $connection = null) {}

    /**
     * @return array<int, array{name: string, rows: int|null, structure_only: bool}>
     */
    public function tables(): array
    {
        return collect($this->tableNames())
            ->map(fn (string $table) => [
                'name' => $table,
                'rows' => in_array($table, self::STRUCTURE_ONLY, true) ? null : $this->db()->table($table)->count(),
                'structure_only' => in_array($table, self::STRUCTURE_ONLY, true),
            ])
            ->all();
    }

    /**
     * Streams the dump to the given writer, one statement at a time, so
     * memory stays flat however large the tables are.
     *
     * @param  callable(string): void  $write
     */
    public function dump(callable $write): void
    {
        $db = $this->db();
        $mysql = $db->getDriverName() === 'mysql' || $db->getDriverName() === 'mariadb';

        $write("-- IMIS database backup\n-- Generated ".now()->toIso8601String().' by '.config('app.name')."\n");
        $write("-- Session/cache/queue/reset-token rows and remember-me tokens are intentionally excluded.\n\n");

        if ($mysql) {
            $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        }

        foreach ($this->tableNames() as $table) {
            $write("-- --------------------------------------------------------\n-- Table `{$table}`\n");
            $write("DROP TABLE IF EXISTS {$this->quoteIdentifier($table)};\n");
            $write($this->createStatement($table).";\n\n");

            if (in_array($table, self::STRUCTURE_ONLY, true)) {
                continue;
            }

            $this->dumpRows($table, $write);
            $write("\n");
        }

        if ($mysql) {
            $write("SET FOREIGN_KEY_CHECKS = 1;\n");
        }
    }

    /**
     * @param  callable(string): void  $write
     */
    private function dumpRows(string $table, callable $write): void
    {
        $db = $this->db();
        $order = collect($db->getSchemaBuilder()->getColumnListing($table))->contains('id') ? 'id' : null;
        $query = $db->table($table);
        $query = $order ? $query->orderBy($order) : $query->orderByRaw('1');

        $query->chunk(self::CHUNK, function ($rows) use ($table, $write) {
            $values = $rows->map(function ($row) use ($table) {
                $row = (array) $row;

                if ($table === 'users' && array_key_exists('remember_token', $row)) {
                    $row['remember_token'] = null;
                }

                return '('.implode(', ', array_map([$this, 'literal'], $row)).')';
            });

            $columns = implode(', ', array_map([$this, 'quoteIdentifier'], array_keys((array) $rows->first())));
            $write("INSERT INTO {$this->quoteIdentifier($table)} ({$columns}) VALUES\n".$values->implode(",\n").";\n");
        });
    }

    public function literal(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            default => $this->db()->getPdo()->quote((string) $value),
        };
    }

    private function quoteIdentifier(string $name): string
    {
        $quote = $this->db()->getDriverName() === 'sqlite' ? '"' : '`';

        return $quote.str_replace($quote, $quote.$quote, $name).$quote;
    }

    private function createStatement(string $table): string
    {
        $db = $this->db();

        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            $row = (array) $db->selectOne('SHOW CREATE TABLE '.$this->quoteIdentifier($table));

            return $row['Create Table'] ?? array_values($row)[1];
        }

        if ($db->getDriverName() === 'sqlite') {
            return (string) $db->selectOne("select sql from sqlite_master where type = 'table' and name = ?", [$table])->sql;
        }

        throw new RuntimeException("Backups aren't supported for the {$db->getDriverName()} driver.");
    }

    /**
     * @return array<int, string>
     */
    private function tableNames(): array
    {
        $db = $this->db();
        // On MySQL the listing spans every database on the server; keep
        // only this application's own database.
        $schema = $db->getDriverName() === 'sqlite' ? null : $db->getDatabaseName();

        return collect($db->getSchemaBuilder()->getTables($schema))
            ->filter(fn (array $table) => $schema === null || ($table['schema'] ?? $schema) === $schema)
            ->pluck('name')
            ->reject(fn (string $name) => str_starts_with($name, 'sqlite_'))
            ->sort()
            ->values()
            ->all();
    }

    private function db(): Connection
    {
        return $this->connection ?? DB::connection();
    }
}
