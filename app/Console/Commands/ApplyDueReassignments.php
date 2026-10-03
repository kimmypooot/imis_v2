<?php

namespace App\Console\Commands;

use App\Support\HR\EmployeeAssignments;
use Illuminate\Console\Command;

/**
 * HR-1b: moves the office of people whose future-dated reassignment has
 * come into effect. Scheduled daily in routes/console.php; each
 * reassignment moves the office once.
 */
class ApplyDueReassignments extends Command
{
    protected $signature = 'imis:apply-reassignments';

    protected $description = 'Move the office of employees whose reassignment takes effect today';

    public function handle(EmployeeAssignments $assignments): int
    {
        $count = $assignments->applyDue();

        $this->info("Applied {$count} reassignment(s).");

        return self::SUCCESS;
    }
}
