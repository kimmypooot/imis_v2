<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Security\AccountLifecycle;
use Illuminate\Console\Command;

/**
 * Admin-3: deactivates the accounts of separated employees and, when set,
 * of accounts nobody has signed in to for too long (AccountLifecycle).
 * Scheduled nightly in routes/console.php; `--dry-run` lists who would be.
 */
class AccountLifecycleCommand extends Command
{
    protected $signature = 'imis:account-lifecycle {--dry-run : List the accounts without deactivating them}';

    protected $description = 'Deactivate accounts of separated employees and dormant accounts';

    public function handle(AccountLifecycle $lifecycle): int
    {
        if ($this->option('dry-run')) {
            $due = $lifecycle->due();
            $rows = collect([
                ...array_map(fn (User $user) => [$user->username, $user->name, 'Separated'], $due['separated']),
                ...array_map(fn (User $user) => [$user->username, $user->name, 'No recent sign-in'], $due['dormant']),
                ...array_map(fn (User $user) => [$user->username, $user->name, 'Skipped: Super Administrator'], $due['skipped']),
            ]);
            $rows->isEmpty() ? $this->info('No account is due.') : $this->table(['Username', 'Name', 'Why'], $rows->all());

            return self::SUCCESS;
        }

        $counts = $lifecycle->sweep();
        $this->info("Deactivated {$counts['separated']} separated and {$counts['dormant']} dormant accounts.");

        if ($counts['skipped']) {
            $this->warn("{$counts['skipped']} Super Administrator account(s) are due but were left active: review them by hand.");
        }

        return self::SUCCESS;
    }
}
