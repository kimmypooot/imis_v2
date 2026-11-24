<?php

namespace App\Console\Commands;

use App\Support\HR\NonPlantilla\NonPlantilla;
use Illuminate\Console\Command;

/**
 * Brings the employment status and account position of everyone with a
 * non-plantilla contract in step with it (the running contract, else the
 * latest). Saving a contract does this for one person; this catches up
 * everyone at once, e.g. after the position sync was introduced.
 */
class SyncContractPositions extends Command
{
    protected $signature = 'imis:sync-contract-positions {--dry-run : List what would change without saving}';

    protected $description = 'Set the position and status of everyone with a contract from their running (or latest) contract';

    public function handle(NonPlantilla $nonPlantilla): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changes = $nonPlantilla->syncAll($dryRun);

        foreach ($changes as $change) {
            $parts = [];
            if ($change['position']) {
                $parts[] = 'position ['.($change['position'][0] ?: 'blank').'] → ['.$change['position'][1].']';
            }
            if ($change['status']) {
                $parts[] = 'status ['.($change['status'][0] ?: 'not set').'] → ['.$change['status'][1].']';
            }
            $this->line("{$change['employee']}: ".implode('; ', $parts));
        }

        $this->info(($dryRun ? 'Would update ' : 'Updated ')."{$changes->count()} employee(s).");

        return self::SUCCESS;
    }
}
