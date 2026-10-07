<?php

namespace App\Support\Backup;

use App\Support\Settings\Settings;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Database backups kept on the server, in private storage under
 * `backups/` (never web-reachable). Made nightly by `imis:backup` and on
 * demand from Admin → Backup Database; only the newest `backup_keep_count`
 * are kept.
 *
 * Files are named imis-backup-YYYY-MM-DD-HHMMSS-{auto|manual}.sql[.gz].
 * Only names matching that pattern are ever read or deleted, so a name
 * from a request can't reach any other file.
 */
class BackupStore
{
    public const DIRECTORY = 'backups';

    private const NAME_PATTERN = '/^imis-backup-(\d{4}-\d{2}-\d{2}-\d{6})-(auto|manual)\.sql(\.gz)?$/';

    public function __construct(private readonly DatabaseDumper $dumper) {}

    /**
     * Writes a new backup and returns its details. The dump goes to a
     * temporary file first, so a failed run never leaves a partial backup
     * that looks complete.
     *
     * @return array{name: string, size: int, created_at: Carbon, automatic: bool}
     */
    public function create(bool $automatic): array
    {
        $gzip = function_exists('gzopen');
        $name = 'imis-backup-'.now()->format('Y-m-d-His').'-'.($automatic ? 'auto' : 'manual').'.sql'.($gzip ? '.gz' : '');

        $this->disk()->makeDirectory(self::DIRECTORY);
        $path = $this->disk()->path(self::DIRECTORY.'/'.$name);
        $partial = $path.'.partial';

        $handle = $gzip ? gzopen($partial, 'wb6') : fopen($partial, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not open the backup file for writing.');
        }

        try {
            $this->dumper->dump(function (string $chunk) use ($handle, $gzip) {
                $written = $gzip ? gzwrite($handle, $chunk) : fwrite($handle, $chunk);
                if ($written === false) {
                    throw new RuntimeException('Could not write the backup file (is the disk full?).');
                }
            });
        } catch (Throwable $e) {
            $gzip ? gzclose($handle) : fclose($handle);
            @unlink($partial);

            throw $e;
        }

        $gzip ? gzclose($handle) : fclose($handle);
        if (! rename($partial, $path)) {
            @unlink($partial);

            throw new RuntimeException('Could not finish the backup file.');
        }

        return $this->describe($name);
    }

    /**
     * Newest first.
     *
     * @return array<int, array{name: string, size: int, created_at: Carbon, automatic: bool}>
     */
    public function all(): array
    {
        return collect($this->disk()->files(self::DIRECTORY))
            ->map(fn (string $path) => basename($path))
            ->filter(fn (string $name) => self::isBackupName($name))
            ->sortDesc()
            ->values()
            ->map(fn (string $name) => $this->describe($name))
            ->all();
    }

    /**
     * @return array{name: string, size: int, created_at: Carbon, automatic: bool}|null
     */
    public function latest(): ?array
    {
        return $this->all()[0] ?? null;
    }

    public function exists(string $name): bool
    {
        return self::isBackupName($name) && $this->disk()->exists(self::DIRECTORY.'/'.$name);
    }

    /** Absolute path of a stored backup, for streaming a download. */
    public function path(string $name): string
    {
        if (! $this->exists($name)) {
            throw new RuntimeException('No such backup.');
        }

        return $this->disk()->path(self::DIRECTORY.'/'.$name);
    }

    public function delete(string $name): bool
    {
        return $this->exists($name) && $this->disk()->delete(self::DIRECTORY.'/'.$name);
    }

    /**
     * Deletes all but the newest `$keep` backups (default: the setting).
     *
     * @return array<int, string> the names deleted
     */
    public function prune(?int $keep = null): array
    {
        $keep ??= max(1, (int) Settings::get('backup_keep_count'));

        return collect($this->all())
            ->slice($keep)
            ->pluck('name')
            ->filter(fn (string $name) => $this->delete($name))
            ->values()
            ->all();
    }

    public static function isBackupName(string $name): bool
    {
        return (bool) preg_match(self::NAME_PATTERN, $name);
    }

    /**
     * @return array{name: string, size: int, created_at: Carbon, automatic: bool}
     */
    private function describe(string $name): array
    {
        preg_match(self::NAME_PATTERN, $name, $parts);

        return [
            'name' => $name,
            'size' => (int) $this->disk()->size(self::DIRECTORY.'/'.$name),
            'created_at' => Carbon::createFromFormat('Y-m-d-His', $parts[1]),
            'automatic' => $parts[2] === 'auto',
        ];
    }

    private function disk(): Filesystem
    {
        return Storage::disk('local');
    }
}
