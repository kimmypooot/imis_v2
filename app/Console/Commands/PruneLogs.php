<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\LogRetention;
use Illuminate\Console\Command;

/**
 * Deletes login history, system access logs and audit entries older than
 * their retention period (System Settings → Log Retention). Scheduled
 * nightly in routes/console.php. A run that deletes anything is recorded
 * in the audit log, with the counts.
 */
class PruneLogs extends Command
{
    protected $signature = 'imis:prune-logs {--dry-run : Show what would be deleted without deleting it}';

    protected $description = 'Delete logs older than their retention period';

    public function handle(LogRetention $retention, AuditLogger $audit): int
    {
        $cutoffs = $retention->cutoffs();

        if ($this->option('dry-run')) {
            $preview = $retention->preview();
            $this->table(['Log', 'Delete before', 'Rows'], collect($cutoffs)->map(fn ($cutoff, $log) => [
                $log, $cutoff?->toDateString() ?? 'kept forever', $preview[$log],
            ])->values()->all());

            return self::SUCCESS;
        }

        $deleted = $retention->prune();
        $total = array_sum($deleted);

        if ($total > 0) {
            $audit->recordFor(null, AuditEvent::LogsPruned, "Deleted {$total} log entries past their retention period", null, [
                'deleted' => $deleted,
                'cutoffs' => collect($cutoffs)->map(fn ($cutoff) => $cutoff?->toDateString())->all(),
            ]);
        }

        foreach ($deleted as $log => $count) {
            $this->info("{$log}: {$count} deleted");
        }

        return self::SUCCESS;
    }
}
