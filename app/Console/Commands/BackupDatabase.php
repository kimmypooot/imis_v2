<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Backup\BackupStore;
use App\Support\Settings\Settings;
use Illuminate\Console\Command;
use Throwable;

/**
 * Saves a database backup in private storage and deletes all but the
 * newest `backup_keep_count`. Scheduled nightly with --if-enabled, which
 * skips the run while System Settings → Automatic Backups is off; run by
 * hand, it always backs up.
 */
class BackupDatabase extends Command
{
    protected $signature = 'imis:backup {--if-enabled : Skip unless automatic backups are turned on in System Settings}';

    protected $description = 'Save a database backup on the server and delete old ones';

    public function handle(BackupStore $store, AuditLogger $audit): int
    {
        if ($this->option('if-enabled') && ! Settings::get('backup_schedule_enabled')) {
            $this->info('Skipped: automatic backups are turned off in System Settings.');

            return self::SUCCESS;
        }

        try {
            $backup = $store->create(automatic: true);
        } catch (Throwable $e) {
            $audit->recordFor(null, AuditEvent::DatabaseBackupFailed, 'Automatic database backup failed', null, ['error' => str($e->getMessage())->limit(200)->value()]);
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $pruned = $store->prune();

        $audit->recordFor(null, AuditEvent::DatabaseBackupCreated, "Automatic database backup {$backup['name']}", null, [
            'file' => $backup['name'], 'size' => $backup['size'], 'pruned' => $pruned,
        ]);

        $this->info("Saved {$backup['name']} (".number_format($backup['size'] / 1024).' KB).');
        $this->info('Deleted '.count($pruned).' old backup(s).');

        return self::SUCCESS;
    }
}
