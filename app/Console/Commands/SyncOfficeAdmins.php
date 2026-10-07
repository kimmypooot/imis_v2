<?php

namespace App\Console\Commands;

use App\Support\SystemAccess\OfficeAdminAccess;
use Illuminate\Console\Command;

/**
 * Re-applies every Division Chief / Field Director designation to the
 * office's systems (and undoes any left over). Saving a user, a system or
 * its roles already does this; run it after a bulk import or a deploy.
 */
class SyncOfficeAdmins extends Command
{
    protected $signature = 'imis:sync-office-admins';

    protected $description = 'Give Division Chiefs and Field Directors admin access to their office\'s systems';

    public function handle(OfficeAdminAccess $offices): int
    {
        $this->info('Synced '.$offices->syncAll().' designated user(s).');

        return self::SUCCESS;
    }
}
