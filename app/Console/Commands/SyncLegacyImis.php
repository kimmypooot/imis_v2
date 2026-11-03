<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Legacy\LegacyBridge;
use Illuminate\Console\Command;
use Throwable;

/**
 * Mirrors every account (or one) and its system access into the legacy
 * database. Changes already sync as they happen; this is the scheduled
 * catch-up for a sync that failed, and the first full sync when the
 * bridge is switched on.
 */
class SyncLegacyImis extends Command
{
    protected $signature = 'imis:sync-legacy {--user= : Only this username}';

    protected $description = 'Mirror accounts and system access into the legacy IMIS database';

    public function handle(LegacyBridge $bridge): int
    {
        if (! $bridge->enabled()) {
            $this->warn('Skipped: the legacy bridge is off (LEGACY_BRIDGE=false).');

            return self::SUCCESS;
        }

        $synced = 0;
        $failed = 0;

        User::query()
            ->with('organizationalUnit')
            ->when($this->option('user'), fn ($query, $username) => $query->where('username', $username))
            ->chunkById(100, function ($users) use ($bridge, &$synced, &$failed) {
                foreach ($users as $user) {
                    try {
                        $bridge->sync($user);
                        $synced++;
                    } catch (Throwable $e) {
                        report($e);
                        $this->error("{$user->username}: {$e->getMessage()}");
                        $failed++;
                    }
                }
            });

        $this->info("Synced {$synced} account(s)".($failed ? ", {$failed} failed." : '.'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
