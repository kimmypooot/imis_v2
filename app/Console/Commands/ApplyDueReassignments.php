<?php

namespace App\Console\Commands;

use App\Support\HR\EmployeeAssignments;
use App\Support\SystemAccess\OfficeAdminAccess;
use Illuminate\Console\Command;

/**
 * HR-1b: moves the office of people whose future-dated reassignment has
 * come into effect, and hands an office's staff over to (or back from)
 * someone heading it by designation once that starts or ends. Scheduled
 * daily in routes/console.php; each happens once.
 */
class ApplyDueReassignments extends Command
{
    protected $signature = 'imis:apply-reassignments';

    protected $description = 'Move the office of employees whose reassignment takes effect today, and settle head-of-office supervision';

    public function handle(EmployeeAssignments $assignments, OfficeAdminAccess $offices): int
    {
        $count = $assignments->applyDue();
        // A move ends a Division Chief / Field Director designation: take its admin access back.
        $offices->syncAll();
        $supervision = $assignments->settleSupervision();

        $this->info("Applied {$count} reassignment(s).");
        $this->info("Handed {$supervision['handed']} office(s) over to a designated head; gave {$supervision['restored']} back.");

        return self::SUCCESS;
    }
}
