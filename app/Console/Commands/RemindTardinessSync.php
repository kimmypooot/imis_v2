<?php

namespace App\Console\Commands;

use App\Support\HR\Dtr\TardinessDeductions;
use Illuminate\Console\Command;

/**
 * DTR: reminds HR that last month's tardiness and undertime still has to be
 * synced to leave credits. Skipped on the 1st, while biometric logs are
 * still being imported; each person is told once per month.
 */
class RemindTardinessSync extends Command
{
    protected $signature = 'imis:remind-tardiness-sync {--force : Also run on the 1st of the month}';

    protected $description = 'Remind HR to sync last month\'s tardiness with leave credits';

    public function handle(TardinessDeductions $deductions): int
    {
        if (today()->day < 2 && ! $this->option('force')) {
            $this->info('Too early in the month; nothing sent.');

            return self::SUCCESS;
        }

        $sent = $deductions->remindSyncDue(today()->subMonthNoOverflow()->startOfMonth());
        $this->info("{$sent} reminder(s) sent.");

        return self::SUCCESS;
    }
}
