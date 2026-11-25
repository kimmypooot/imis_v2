<?php

namespace App\Console\Commands;

use App\Support\HR\Plantilla\PlantillaTitleChecks;
use Illuminate\Console\Command;

/**
 * HR-4: tells HR when an item holder's account title differs from the
 * item they hold (PlantillaTitleChecks). Re-running never repeats a notice.
 */
class CheckPlantillaTitles extends Command
{
    protected $signature = 'imis:check-plantilla-titles';

    protected $description = 'Tell HR about item holders whose account position title differs from the plantilla';

    public function handle(PlantillaTitleChecks $checks): int
    {
        $count = $checks->mismatches()->count();
        $told = $checks->notify();

        $this->info("{$count} title difference(s); {$told} person(s) notified.");

        return self::SUCCESS;
    }
}
