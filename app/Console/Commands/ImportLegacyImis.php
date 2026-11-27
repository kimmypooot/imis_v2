<?php

namespace App\Console\Commands;

use App\Support\Legacy\LegacyImporter;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SplFileObject;

/**
 * Imports the legacy IMIS database into this platform. Either loads a
 * phpMyAdmin dump (old-imis/db_imis.sql) into a temporary schema on the
 * same MySQL server and drops it afterwards, or reads a schema where the
 * legacy tables are already loaded (--database), or reads the live legacy
 * database through its own connection (--live, LEGACY_DB_*) where the
 * hosting doesn't let IMIS create a temporary schema. It only reads the
 * legacy tables.
 */
class ImportLegacyImis extends Command
{
    private const CONNECTION = 'legacy_import';

    protected $signature = 'imis:import-legacy
        {dump? : Path to the legacy SQL dump (e.g. ../old-imis/db_imis.sql)}
        {--database= : Read an existing schema holding the legacy tables instead of loading a dump}
        {--live : Read the live legacy database set by LEGACY_DB_* instead of loading a dump}
        {--legacy-url=https://imis.cscro8.com : Base URL the legacy relative launch paths resolve against}
        {--photos= : Folder holding legacy profile pictures (default: admin/uploads next to the dump)}
        {--without-history : Skip the sign-in and system-access history}';

    protected $description = 'Import accounts, systems, access, announcements and history from the legacy IMIS database';

    public function handle(): int
    {
        $dump = $this->argument('dump');
        $database = $this->option('database');
        $live = (bool) $this->option('live');

        if (! $dump && ! $database && ! $live) {
            $this->error('Give the dump path, --database for a schema that already holds the legacy tables, or --live for the legacy database in LEGACY_DB_*.');

            return self::FAILURE;
        }

        if ($dump && ! is_file($dump)) {
            $this->error("No dump at {$dump}.");

            return self::FAILURE;
        }

        $scratch = ($database || $live) ? null : DB::connection()->getDatabaseName().'_legacy_import';

        try {
            if ($scratch) {
                $this->components->task('Loading the legacy dump', fn () => $this->load($dump, $scratch));
            }

            $live ? $this->connectLive() : $this->connect($database ?? $scratch);
            $photos = $this->option('photos') ?? ($dump ? dirname(realpath($dump)).'/admin/uploads' : null);

            $counts = (new LegacyImporter(
                DB::connection(self::CONNECTION),
                (string) $this->option('legacy-url'),
                $photos,
                ! $this->option('without-history'),
            ))->run();
        } finally {
            DB::purge(self::CONNECTION);

            if ($scratch) {
                DB::statement('DROP DATABASE IF EXISTS '.$this->quote($scratch));
            }
        }

        $this->components->info('Legacy IMIS data imported.');
        $this->table(['Imported', 'Count'], collect($counts)->map(fn ($count, $key) => [Str::headline($key), $count])->values());

        return self::SUCCESS;
    }

    /**
     * A connection like the default one, pointed at the legacy schema. The
     * session runs in UTC so legacy TIMESTAMP columns read as true UTC.
     */
    private function connect(string $database): ConnectionInterface
    {
        config(['database.connections.'.self::CONNECTION => array_merge(
            config('database.connections.'.config('database.default')),
            ['database' => $database, 'timezone' => '+00:00'],
        )]);
        DB::purge(self::CONNECTION);

        return DB::connection(self::CONNECTION);
    }

    /**
     * The legacy connection (LEGACY_DB_*, with its own database user), read
     * in UTC like a loaded dump.
     */
    private function connectLive(): ConnectionInterface
    {
        config(['database.connections.'.self::CONNECTION => array_merge(
            config('database.connections.legacy'),
            ['timezone' => '+00:00'],
        )]);
        DB::purge(self::CONNECTION);

        return DB::connection(self::CONNECTION);
    }

    /**
     * Runs the dump statement by statement. phpMyAdmin escapes line breaks
     * inside values, so a line ending in ";" always ends a statement.
     */
    private function load(string $dump, string $scratch): void
    {
        DB::statement('DROP DATABASE IF EXISTS '.$this->quote($scratch));
        DB::statement('CREATE DATABASE '.$this->quote($scratch).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $connection = $this->connect($scratch);

        $statement = '';

        foreach (new SplFileObject($dump) as $line) {
            $trimmed = trim((string) $line);

            if ($statement === '' && ($trimmed === '' || str_starts_with($trimmed, '--'))) {
                continue;
            }

            $statement .= $line;

            if (str_ends_with($trimmed, ';')) {
                $connection->unprepared($statement);
                $statement = '';
            }
        }
    }

    private function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
